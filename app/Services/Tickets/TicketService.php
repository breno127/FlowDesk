<?php

namespace App\Services\Tickets;

use App\Enums\ColumnCategory;
use App\Enums\MessageDirection;
use App\Enums\MessageVisibility;
use App\Enums\SlaTimerType;
use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketMessageCreated;
use App\Events\TicketMoved;
use App\Events\TicketPriorityChanged;
use App\Events\TicketResolved;
use App\Events\TicketUpdated;
use App\Exceptions\Helpdesk\HelpdeskException;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Contact;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketColumnTransition;
use App\Models\TicketMessage;
use App\Models\TicketPriority;
use App\Models\TicketType;
use App\Models\User;
use App\Notifications\MentionedInTicketNotification;
use App\Repositories\Contracts\TicketRepositoryInterface;
use App\Services\Assignment\AssignmentService;
use App\Services\CustomFields\CustomFieldService;
use App\Services\Sla\SlaCalculationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Ponto único de escrita do domínio de tickets.
 *
 * Toda mutação passa por aqui (Filament, Kanban, API, webhooks, automações),
 * dentro de DB::transaction, registrando auditoria e disparando eventos que
 * só chegam aos listeners após o commit.
 */
class TicketService
{
    /** Atributos simples aceitos em create/update. */
    private const ATTRIBUTES = [
        'subject', 'description', 'ticket_type_id', 'ticket_priority_id', 'department_id', 'team_id',
        'contact_id', 'client_company_id', 'channel', 'jira_issue_key', 'meta',
    ];

    /** Mudanças que exigem reaplicar a política de SLA. */
    private const SLA_SENSITIVE = ['ticket_priority_id', 'ticket_type_id', 'client_company_id', 'department_id'];

    public function __construct(
        private readonly TicketRepositoryInterface $tickets,
        private readonly SlaCalculationService $sla,
        private readonly AssignmentService $assignment,
        private readonly CustomFieldService $customFields,
        private readonly TicketNumberGenerator $numbers,
        private readonly MentionParser $mentions,
    ) {}

    // ================================================================== Criação

    /**
     * @param  array{subject: string, description?: ?string, board_id?: ?int, board_column_id?: ?int,
     *     ticket_type_id?: ?int, ticket_priority_id?: ?int, department_id?: ?int, team_id?: ?int,
     *     assignee_id?: ?int, contact_id?: ?int, client_company_id?: ?int, channel?: ?string,
     *     custom_fields?: array<string, mixed>, auto_assign?: bool, meta?: ?array}  $data
     */
    public function create(array $data, ?User $actor = null): Ticket
    {
        return DB::transaction(function () use ($data, $actor) {
            $board = $this->resolveBoard($data);
            $column = $this->resolveColumn($board, $data['board_column_id'] ?? null);
            $priorityId = $data['ticket_priority_id'] ?? TicketPriority::default()?->id ?? throw HelpdeskException::noPriority();

            $attributes = Arr::only($data, self::ATTRIBUTES);
            $attributes['ticket_priority_id'] = $priorityId;
            $attributes['channel'] = $this->normalizeChannel($data['channel'] ?? null);
            $attributes = $this->inferOwnership($attributes, $board);

            $ticket = new Ticket($attributes + [
                'number' => $this->numbers->next(),
                'board_id' => $board->id,
                'board_column_id' => $column->id,
                'position' => $this->tickets->nextPosition($column->id),
                'created_by' => $actor?->id,
                'last_customer_activity_at' => isset($attributes['contact_id']) ? now() : null,
            ]);

            if ($column->isFinal()) {
                $ticket->resolved_at = now();
            }

            $ticket->save();
            $ticket->setRelation('column', $column);

            $this->customFields->save($ticket, $data['custom_fields'] ?? []);
            $this->openTransition($ticket, $column, null, $actor);
            $this->log($ticket, 'created', ['channel' => $ticket->channel], $actor);

            $this->sla->initialize($ticket);

            if (! empty($data['assignee_id'])) {
                $this->applyAssignee($ticket, User::query()->findOrFail($data['assignee_id']), $actor, automatic: false);
            } elseif (($data['auto_assign'] ?? true) && $ticket->team_id) {
                $this->autoAssign($ticket, null, $actor);
            }

            TicketCreated::dispatch($ticket, $actor?->id);

            return $ticket->refresh();
        });
    }

    // ================================================================== Atualização

    /**
     * Atualiza atributos, campos customizados, responsável e coluna numa única transação.
     */
    public function update(Ticket $ticket, array $data, ?User $actor = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $data, $actor) {
            $ticket = $this->tickets->lockForUpdate($ticket->id);

            $attributes = Arr::only($data, self::ATTRIBUTES);
            if (array_key_exists('channel', $attributes)) {
                $attributes['channel'] = $this->normalizeChannel($attributes['channel']);
            }

            $ticket->fill($attributes);
            $dirty = $ticket->getDirty();
            $changes = collect($dirty)
                ->except(['meta', 'description'])
                ->map(fn ($new, $key) => ['old' => $ticket->getOriginal($key), 'new' => $new])
                ->all();

            $ticket->save();

            if (array_key_exists('custom_fields', $data) || array_key_exists('ticket_type_id', $dirty)) {
                $this->customFields->save($ticket, $data['custom_fields'] ?? []);
            }

            if (array_intersect(array_keys($dirty), self::SLA_SENSITIVE) !== []) {
                $this->sla->recalculate($ticket);
            }

            if ($changes !== []) {
                $this->log($ticket, 'updated', ['changes' => $changes], $actor);
                TicketUpdated::dispatch($ticket, $actor?->id, ['changes' => $changes]);
            }

            if (isset($changes['ticket_priority_id'])) {
                TicketPriorityChanged::dispatch($ticket, $actor?->id, [
                    'from_priority_id' => $changes['ticket_priority_id']['old'],
                    'to_priority_id' => $changes['ticket_priority_id']['new'],
                    'changes' => $changes,
                ]);
            }

            if (array_key_exists('assignee_id', $data) && (int) $data['assignee_id'] !== (int) $ticket->assignee_id) {
                $user = $data['assignee_id'] ? User::query()->findOrFail($data['assignee_id']) : null;
                $this->applyAssignee($ticket, $user, $actor, automatic: false);
            }

            if (! empty($data['board_column_id']) && (int) $data['board_column_id'] !== $ticket->board_column_id) {
                $this->moveToColumn($ticket, BoardColumn::query()->findOrFail($data['board_column_id']), null, $actor);
            }

            return $ticket->refresh();
        });
    }

    // ================================================================== Kanban

    /**
     * Move o ticket para uma coluna/posição, aplicando WIP, categoria (resolver/reabrir)
     * e comportamento de SLA da coluna.
     */
    public function moveToColumn(Ticket $ticket, BoardColumn $to, ?int $position = null, ?User $actor = null, bool $enforceWip = true): Ticket
    {
        return DB::transaction(function () use ($ticket, $to, $position, $actor, $enforceWip) {
            $ticket = $this->tickets->lockForUpdate($ticket->id);
            $from = $ticket->column()->firstOrFail();

            if ($to->board_id !== $ticket->board_id) {
                throw HelpdeskException::columnNotInBoard();
            }

            $columnChanged = $from->id !== $to->id;

            if ($columnChanged && $enforceWip && $to->wip_limit
                && ! ($actor?->can('kanban.bypass_wip') ?? false)
                && $this->tickets->countInColumn($to->id) >= $to->wip_limit) {
                throw HelpdeskException::wipLimit($to->name, $to->wip_limit);
            }

            $this->tickets->placeInColumn($ticket, $to->id, $position);

            if (! $columnChanged) {
                $ticket->save();

                return $ticket;
            }

            $becameResolved = false;
            $now = now();

            if ($to->isFinal() && ! $from->isFinal()) {
                $ticket->resolved_at ??= $now;
                $becameResolved = true;
            }
            if ($to->category === ColumnCategory::Closed) {
                $ticket->closed_at ??= $now;
            }
            $reopened = ! $to->isFinal() && $from->isFinal();
            if ($reopened) {
                $ticket->resolved_at = null;
                $ticket->closed_at = null;
            }

            $ticket->save();
            $ticket->setRelation('column', $to);

            $this->closeTransition($ticket, $now);
            $this->openTransition($ticket, $to, $from, $actor);

            if ($reopened) {
                $this->sla->reopen($ticket, $now);
            }
            $this->sla->applyColumnBehavior($ticket, $to, $now);

            $this->log($ticket, 'moved', [
                'from' => ['id' => $from->id, 'name' => $from->name],
                'to' => ['id' => $to->id, 'name' => $to->name],
            ], $actor);

            TicketMoved::dispatch($ticket, $actor?->id, [
                'from_column_id' => $from->id,
                'to_column_id' => $to->id,
                'board_column_id' => $to->id,
            ]);

            if ($becameResolved) {
                TicketResolved::dispatch($ticket, $actor?->id);
            }

            return $ticket;
        });
    }

    /** Move para a primeira coluna "Resolvido" do quadro. */
    public function resolve(Ticket $ticket, ?User $actor = null): Ticket
    {
        $column = $ticket->board->columns()->where('category', ColumnCategory::Resolved->value)->first()
            ?? throw HelpdeskException::noResolvedColumn();

        return $this->moveToColumn($ticket, $column, null, $actor, enforceWip: false);
    }

    // ================================================================== Atribuição

    public function assign(Ticket $ticket, ?User $assignee, ?User $actor = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $assignee, $actor) {
            $ticket = $this->tickets->lockForUpdate($ticket->id);
            $this->applyAssignee($ticket, $assignee, $actor, automatic: false);

            return $ticket;
        });
    }

    /** Distribui via AssignmentService (round-robin ponderado). Retorna o agente escolhido. */
    public function autoAssign(Ticket $ticket, ?Team $team = null, ?User $actor = null): ?User
    {
        return DB::transaction(function () use ($ticket, $team, $actor) {
            $ticket->loadMissing('priority', 'team');

            if ($team && $ticket->team_id !== $team->id) {
                $ticket->forceFill(['team_id' => $team->id])->save();
                $ticket->setRelation('team', $team);
            }

            $agent = $this->assignment->pickAgent($ticket, $team);

            if ($agent) {
                $this->applyAssignee($ticket, $agent, $actor, automatic: true);
            } else {
                $this->log($ticket, 'auto_assign_skipped', ['team_id' => $ticket->team_id], $actor);
            }

            return $agent;
        });
    }

    // ================================================================== Timeline

    /**
     * Adiciona mensagem/nota à timeline.
     *
     * @param  array{body: string, visibility?: string|MessageVisibility, channel?: ?string,
     *     direction?: string|MessageDirection, external_id?: ?string, meta?: ?array}  $data
     * @param  Model|null  $author  User (agente), Contact (cliente) ou null (sistema)
     */
    public function addMessage(Ticket $ticket, array $data, ?Model $author = null): TicketMessage
    {
        return DB::transaction(function () use ($ticket, $data, $author) {
            $ticket = $this->tickets->lockForUpdate($ticket->id);

            $visibility = $data['visibility'] ?? MessageVisibility::Public;
            $visibility = $visibility instanceof MessageVisibility ? $visibility : MessageVisibility::from($visibility);

            $direction = $data['direction'] ?? null;
            $direction = $direction instanceof MessageDirection ? $direction : ($direction !== null
                ? MessageDirection::from($direction)
                : match (true) {
                    $author instanceof Contact => MessageDirection::Inbound,
                    $author instanceof User => MessageDirection::Outbound,
                    default => MessageDirection::System,
                });

            $message = $ticket->messages()->create([
                'author_type' => $author?->getMorphClass(),
                'author_id' => $author?->getKey(),
                'visibility' => $visibility,
                'direction' => $direction,
                'channel' => $this->normalizeChannel($data['channel'] ?? $ticket->channel),
                'body' => $data['body'],
                'external_id' => $data['external_id'] ?? null,
                'meta' => $data['meta'] ?? null,
            ]);

            $now = now();

            if ($direction === MessageDirection::Inbound) {
                $ticket->last_customer_activity_at = $now;
            }

            if ($author instanceof User) {
                $ticket->last_agent_activity_at = $now;

                // Primeira resposta = primeira mensagem PÚBLICA de um agente ao cliente
                if ($visibility === MessageVisibility::Public && $ticket->first_response_at === null) {
                    $ticket->first_response_at = $now;
                    $this->sla->complete($ticket, SlaTimerType::FirstResponse, $now);
                }
            }

            $ticket->save();

            if ($visibility === MessageVisibility::Internal) {
                $this->registerMentions($ticket, $message, $author instanceof User ? $author : null);
            }

            TicketMessageCreated::dispatch($ticket, $message, $author instanceof User ? $author->id : null);

            return $message;
        });
    }

    // ================================================================== Outros

    public function recordCsat(Ticket $ticket, int $score, ?string $comment = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $score, $comment) {
            $ticket = $this->tickets->lockForUpdate($ticket->id);
            $ticket->forceFill([
                'csat_score' => max(1, min(5, $score)),
                'csat_comment' => $comment,
                'csat_answered_at' => now(),
            ])->save();

            $this->log($ticket, 'csat_recorded', ['score' => $ticket->csat_score]);

            return $ticket;
        });
    }

    public function delete(Ticket $ticket, ?User $actor = null): void
    {
        DB::transaction(function () use ($ticket, $actor) {
            $this->log($ticket, 'deleted', [], $actor);
            $ticket->delete();
        });
    }

    public function restore(Ticket $ticket, ?User $actor = null): void
    {
        DB::transaction(function () use ($ticket, $actor) {
            $ticket->restore();
            $this->log($ticket, 'restored', [], $actor);
        });
    }

    // ================================================================== Internos

    private function applyAssignee(Ticket $ticket, ?User $assignee, ?User $actor, bool $automatic): void
    {
        $previous = $ticket->assignee_id;

        if ($previous === $assignee?->id) {
            return;
        }

        $ticket->forceFill([
            'assignee_id' => $assignee?->id,
            'assigned_at' => $assignee ? now() : null,
        ])->save();
        $ticket->setRelation('assignee', $assignee);

        $this->log($ticket, 'assigned', [
            'from' => $previous,
            'to' => $assignee?->id,
            'automatic' => $automatic,
        ], $actor);

        TicketAssigned::dispatch($ticket, $actor?->id, [
            'previous_assignee_id' => $previous,
            'assignee_id' => $assignee?->id,
            'automatic' => $automatic,
        ]);
    }

    private function registerMentions(Ticket $ticket, TicketMessage $message, ?User $author): void
    {
        $users = $this->mentions->resolve($message->body)
            ->reject(fn (User $u) => $u->id === $author?->id);

        if ($users->isEmpty()) {
            return;
        }

        $message->mentions()->syncWithPivotValues($users->pluck('id')->all(), ['notified_at' => now()]);

        DB::afterCommit(fn () => Notification::send($users, new MentionedInTicketNotification($ticket, $message, $author)));
    }

    private function openTransition(Ticket $ticket, BoardColumn $column, ?BoardColumn $from, ?User $actor): void
    {
        TicketColumnTransition::query()->create([
            'ticket_id' => $ticket->id,
            'board_column_id' => $column->id,
            'from_column_id' => $from?->id,
            'user_id' => $actor?->id,
            'entered_at' => now(),
        ]);
    }

    private function closeTransition(Ticket $ticket, $at): void
    {
        $open = TicketColumnTransition::query()
            ->where('ticket_id', $ticket->id)
            ->whereNull('left_at')
            ->latest('entered_at')
            ->first();

        $open?->forceFill([
            'left_at' => $at,
            'duration_seconds' => max(0, $at->getTimestamp() - $open->entered_at->getTimestamp()),
        ])->save();
    }

    private function log(Ticket $ticket, string $event, array $properties = [], ?User $actor = null): void
    {
        TicketActivity::query()->create([
            'ticket_id' => $ticket->id,
            'causer_id' => $actor?->id,
            'event' => $event,
            'properties' => $properties ?: null,
        ]);
    }

    private function resolveBoard(array $data): Board
    {
        if (! empty($data['board_id'])) {
            return Board::query()->findOrFail($data['board_id']);
        }

        if (! empty($data['board_column_id'])) {
            return BoardColumn::query()->findOrFail($data['board_column_id'])->board;
        }

        return Board::query()->where('is_default', true)->where('is_active', true)->first()
            ?? Board::query()->where('is_active', true)->orderBy('id')->first()
            ?? throw HelpdeskException::noDefaultBoard();
    }

    private function resolveColumn(Board $board, ?int $columnId): BoardColumn
    {
        if ($columnId) {
            $column = BoardColumn::query()->findOrFail($columnId);

            if ($column->board_id !== $board->id) {
                throw HelpdeskException::columnNotInBoard();
            }

            return $column;
        }

        return $board->initialColumn() ?? throw HelpdeskException::boardWithoutColumns();
    }

    /** Herda departamento/equipe/empresa quando não informados. */
    private function inferOwnership(array $attributes, Board $board): array
    {
        if (empty($attributes['department_id']) && ! empty($attributes['ticket_type_id'])) {
            $attributes['department_id'] = TicketType::query()->whereKey($attributes['ticket_type_id'])->value('department_id');
        }

        $attributes['department_id'] ??= $board->department_id;
        $attributes['team_id'] ??= $board->team_id;

        if (empty($attributes['client_company_id']) && ! empty($attributes['contact_id'])) {
            $attributes['client_company_id'] = Contact::query()->whereKey($attributes['contact_id'])->value('client_company_id');
        }

        return $attributes;
    }

    private function normalizeChannel(?string $channel): string
    {
        $channels = array_keys(config('helpdesk.channels', []));

        return in_array($channel, $channels, true) ? $channel : ($channels[0] ?? 'portal');
    }
}
