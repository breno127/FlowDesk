<?php

namespace App\Filament\Pages;

use App\Enums\ColumnCategory;
use App\Enums\SlaBehavior;
use App\Exceptions\Helpdesk\HelpdeskException;
use App\Filament\Resources\BoardResource;
use App\Filament\Resources\TicketResource;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketType;
use App\Models\User;
use App\Repositories\Contracts\TicketRepositoryInterface;
use App\Services\Kanban\BoardService;
use App\Services\Sla\SlaCalculationService;
use App\Services\Tickets\TicketService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Kanban 100% dinâmico: colunas vêm de board_columns, cards respeitam a
 * visibilidade RBAC do usuário e todo movimento passa pelo TicketService
 * (WIP, SLA, automações, auditoria).
 */
class KanbanBoard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationGroup = 'Atendimento';

    protected static ?string $navigationLabel = 'Kanban';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'kanban';

    protected static string $view = 'filament.pages.kanban-board';

    #[Url(as: 'board')]
    public ?int $boardId = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'agent', except: '')]
    public string $assignee = '';

    #[Url(as: 'team', except: '')]
    public string $teamId = '';

    #[Url(as: 'priority', except: '')]
    public string $priorityId = '';

    #[Url(as: 'type', except: '')]
    public string $typeId = '';

    #[Url(as: 'sla', except: '')]
    public string $slaState = '';

    /** Incrementado após cada movimento: força a reconstrução limpa do DOM das colunas. */
    public int $version = 0;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('kanban.view') ?? false;
    }

    public function mount(): void
    {
        $accessible = Board::query()->accessibleBy(auth()->user())->where('is_active', true);

        if (! $this->boardId || ! (clone $accessible)->whereKey($this->boardId)->exists()) {
            $this->boardId = (clone $accessible)->orderByDesc('is_default')->orderBy('name')->value('id');
        }
    }

    public function getTitle(): string
    {
        return $this->board?->name ?? 'Kanban';
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    // ================================================================== Dados

    #[Computed]
    public function board(): ?Board
    {
        return $this->boardId ? Board::query()->with('columns')->find($this->boardId) : null;
    }

    /** @return Collection<int, Collection<int, Ticket>> */
    #[Computed]
    public function cards(): Collection
    {
        if (! $this->board) {
            return collect();
        }

        return app(TicketRepositoryInterface::class)->kanbanCards(
            $this->board,
            auth()->user(),
            [
                'search' => trim($this->search) ?: null,
                'assignee_id' => match ($this->assignee) {
                    '' => null,
                    'me' => auth()->id(),
                    default => $this->assignee,
                },
                'team_id' => $this->teamId ?: null,
                'ticket_priority_id' => $this->priorityId ?: null,
                'ticket_type_id' => $this->typeId ?: null,
                'sla_state' => $this->slaState ?: null,
            ],
            (int) config('helpdesk.kanban.cards_per_column', 100),
        );
    }

    /** @return array<int, int> */
    #[Computed]
    public function counts(): array
    {
        return $this->board ? app(TicketRepositoryInterface::class)->countsByColumn($this->board) : [];
    }

    #[Computed]
    public function filterOptions(): array
    {
        $user = auth()->user();
        // N1 só enxerga a própria equipe: filtros não oferecem o que ele não pode ver
        $teamIds = $user->isKanbanOnly() ? $user->teamIds() : null;

        return [
            'boards' => Board::query()->accessibleBy($user)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'agents' => User::query()->agents()
                ->when($teamIds !== null, fn ($query) => $query->whereHas('teams', fn ($teams) => $teams->whereIn('teams.id', $teamIds)))
                ->orderBy('name')->pluck('name', 'id')->all(),
            'teams' => Team::query()->where('is_active', true)
                ->when($teamIds !== null, fn ($query) => $query->whereIn('id', $teamIds))
                ->orderBy('tier')->orderBy('name')->pluck('name', 'id')->all(),
            'priorities' => TicketPriority::query()->orderByDesc('level')->pluck('name', 'id')->all(),
            'types' => TicketType::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    public function slaFor(Ticket $ticket): ?\App\Services\Sla\SlaSnapshot
    {
        return app(SlaCalculationService::class)->mostUrgent($ticket);
    }

    public function canMove(): bool
    {
        return auth()->user()->can('tickets.move');
    }

    // ================================================================== Interação

    /** Chamado pelo Sortable.js ao soltar um card. */
    public function moveTicket(int $ticketId, int $columnId, int $position): void
    {
        $this->version++;

        $ticket = Ticket::query()->visibleTo(auth()->user())->find($ticketId);
        $column = BoardColumn::query()->find($columnId);

        if (! $ticket || ! $column || $column->board_id !== $this->boardId) {
            Notification::make()->title('Ticket ou coluna indisponível')->danger()->send();

            return;
        }

        if (! Gate::allows('move', $ticket)) {
            Notification::make()->title('Sem permissão para mover este ticket')->danger()->send();

            return;
        }

        try {
            app(TicketService::class)->moveToColumn($ticket, $column, max(0, $position), auth()->user());

            if ($ticket->board_column_id !== $column->id) {
                Notification::make()
                    ->title("{$ticket->number} → {$column->name}")
                    ->success()
                    ->duration(2500)
                    ->send();
            }
        } catch (HelpdeskException $e) {
            Notification::make()->title('Movimento bloqueado')->body($e->getMessage())->warning()->send();
        }

        unset($this->cards, $this->counts);
    }

    public function updated(string $property): void
    {
        if ($property === 'boardId') {
            unset($this->board);
        }
        unset($this->cards, $this->counts);
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'assignee', 'teamId', 'priorityId', 'typeId', 'slaState']);
        unset($this->cards);
    }

    public function refreshBoard(): void
    {
        unset($this->cards, $this->counts);
    }

    // ================================================================== Ações do cabeçalho

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newTicket')
                ->label('Novo ticket')
                ->icon('heroicon-o-plus')
                ->visible(fn () => Gate::allows('create', Ticket::class))
                ->url(fn () => TicketResource::getUrl('create', ['board' => $this->boardId])),

            Action::make('manageColumns')
                ->label('Colunas')
                ->icon('heroicon-o-adjustments-vertical')
                ->color('gray')
                ->visible(fn () => $this->board && auth()->user()->can('kanban.manage_columns'))
                ->modalHeading(fn () => 'Colunas — '.$this->board?->name)
                ->modalDescription('Arraste para reordenar. Colunas com tickets não podem ser removidas.')
                ->modalWidth(MaxWidth::FiveExtraLarge)
                ->fillForm(fn () => ['columns' => $this->board->columns->map(fn (BoardColumn $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'color' => $c->color,
                    'category' => $c->category->value,
                    'sla_behavior' => $c->sla_behavior->value,
                    'wip_limit' => $c->wip_limit,
                    'is_initial' => $c->is_initial,
                ])->all()])
                ->form([static::columnsRepeater()])
                ->action(function (array $data) {
                    try {
                        app(BoardService::class)->syncColumns($this->board, $data['columns']);
                        $this->version++;
                        unset($this->board, $this->cards, $this->counts);
                        Notification::make()->title('Colunas atualizadas')->success()->send();
                    } catch (HelpdeskException $e) {
                        Notification::make()->title('Não foi possível salvar')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('boards')
                ->label('Quadros')
                ->icon('heroicon-o-rectangle-group')
                ->color('gray')
                ->visible(fn () => auth()->user()->can('kanban.manage_boards'))
                ->url(fn () => BoardResource::getUrl()),
        ];
    }

    /** Repeater de colunas compartilhado com o BoardResource. */
    public static function columnsRepeater(): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make('columns')
            ->hiddenLabel()
            ->reorderable()
            ->reorderableWithButtons()
            ->collapsible()
            ->cloneable()
            ->minItems(1)
            ->defaultItems(1)
            ->addActionLabel('Adicionar coluna')
            ->itemLabel(fn (array $state) => $state['name'] ?? 'Nova coluna')
            ->columns(6)
            ->schema([
                Forms\Components\Hidden::make('id'),
                Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(60)->columnSpan(2)->live(onBlur: true),
                Forms\Components\ColorPicker::make('color')->label('Cor')->default('#8dbea3'),
                Forms\Components\Select::make('category')->label('Categoria')
                    ->options(ColumnCategory::class)
                    ->default(ColumnCategory::Open->value)
                    ->required()
                    ->helperText('Base das métricas'),
                Forms\Components\Select::make('sla_behavior')->label('SLA nesta coluna')
                    ->options(SlaBehavior::class)
                    ->default(SlaBehavior::Run->value)
                    ->required(),
                Forms\Components\TextInput::make('wip_limit')->label('Limite WIP')->numeric()->minValue(1)->placeholder('∞'),
                Forms\Components\Toggle::make('is_initial')->label('Coluna de entrada')->inline(false),
            ]);
    }
}
