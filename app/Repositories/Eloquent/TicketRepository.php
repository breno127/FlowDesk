<?php

namespace App\Repositories\Eloquent;

use App\Enums\SlaTimerStatus;
use App\Models\Board;
use App\Models\Ticket;
use App\Models\User;
use App\Repositories\Contracts\TicketRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TicketRepository implements TicketRepositoryInterface
{
    public function kanbanCards(Board $board, User $viewer, array $filters, int $perColumn): Collection
    {
        $columnIds = $board->columns()->pluck('id');

        return $columnIds->mapWithKeys(function (int $columnId) use ($viewer, $filters, $perColumn) {
            $tickets = Ticket::query()
                ->visibleTo($viewer)
                ->where('board_column_id', $columnId)
                ->with([
                    'priority:id,name,color,level',
                    'type:id,name,color,icon',
                    'assignee:id,name,avatar_path',
                    'contact:id,name',
                    'company:id,name',
                    'slaTimers' => fn ($q) => $q->where('status', '!=', SlaTimerStatus::Cancelled->value)->with('schedule'),
                ])
                ->withCount(['messages as internal_notes_count' => fn ($q) => $q->where('visibility', 'internal')])
                ->tap(fn (Builder $q) => $this->applyFilters($q, $filters))
                ->orderBy('position')
                ->orderBy('id')
                ->limit($perColumn)
                ->get();

            return [$columnId => $tickets];
        });
    }

    public function countsByColumn(Board $board): array
    {
        return Ticket::query()
            ->where('board_id', $board->id)
            ->selectRaw('board_column_id, COUNT(*) as total')
            ->groupBy('board_column_id')
            ->pluck('total', 'board_column_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    public function countInColumn(int $columnId): int
    {
        return Ticket::query()->where('board_column_id', $columnId)->count();
    }

    public function nextPosition(int $columnId): int
    {
        return (int) Ticket::query()->where('board_column_id', $columnId)->max('position') + 1;
    }

    public function placeInColumn(Ticket $ticket, int $columnId, ?int $position): void
    {
        $fromColumnId = (int) $ticket->getOriginal('board_column_id');

        // Ordem atual da coluna de destino, sem o próprio ticket
        $ids = Ticket::query()
            ->where('board_column_id', $columnId)
            ->whereKeyNot($ticket->id)
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        $position = $position === null ? count($ids) : max(0, min($position, count($ids)));
        array_splice($ids, $position, 0, [$ticket->id]);

        $ticket->board_column_id = $columnId;
        $ticket->position = $position;

        $this->writePositions($ids);

        // O ticket ainda está gravado na coluna de origem: exclui-o da recompactação
        if ($fromColumnId && $fromColumnId !== $columnId) {
            $this->compact($fromColumnId, $ticket->id);
        }
    }

    public function lockForUpdate(int $ticketId): Ticket
    {
        return Ticket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
    }

    private function compact(int $columnId, int $excludeTicketId): void
    {
        $ids = Ticket::query()
            ->where('board_column_id', $columnId)
            ->whereKeyNot($excludeTicketId)
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        $this->writePositions($ids);
    }

    /** UPDATE único com CASE — evita N queries ao reordenar colunas grandes. */
    private function writePositions(array $orderedIds): void
    {
        if ($orderedIds === []) {
            return;
        }

        $cases = [];
        $bindings = [];
        foreach (array_values($orderedIds) as $index => $id) {
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = (int) $id;
            $bindings[] = $index;
        }

        $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));

        DB::update(
            'UPDATE tickets SET position = CASE id '.implode(' ', $cases).' END WHERE id IN ('.$placeholders.')',
            [...$bindings, ...array_map('intval', $orderedIds)]
        );
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                $q->where(fn (Builder $w) => $w
                    ->where('subject', 'like', "%{$term}%")
                    ->orWhere('number', 'like', "%{$term}%"));
            })
            ->when(($filters['assignee_id'] ?? null) === 'none', fn (Builder $q) => $q->whereNull('assignee_id'))
            ->when(is_numeric($filters['assignee_id'] ?? null), fn (Builder $q) => $q->where('assignee_id', (int) $filters['assignee_id']))
            ->when($filters['team_id'] ?? null, fn (Builder $q, $id) => $q->where('team_id', $id))
            ->when($filters['ticket_priority_id'] ?? null, fn (Builder $q, $id) => $q->where('ticket_priority_id', $id))
            ->when($filters['ticket_type_id'] ?? null, fn (Builder $q, $id) => $q->where('ticket_type_id', $id))
            ->when(($filters['sla_state'] ?? null) === 'breached', fn (Builder $q) => $q->whereHas(
                'slaTimers', fn (Builder $t) => $t->whereNotNull('breached_at')
            ))
            ->when(($filters['sla_state'] ?? null) === 'at_risk', fn (Builder $q) => $q->whereHas(
                'slaTimers', fn (Builder $t) => $t->where('status', SlaTimerStatus::Running->value)
                    ->whereNull('breached_at')->whereNotNull('warned_at')
            ));
    }
}
