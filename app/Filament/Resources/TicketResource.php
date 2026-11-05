<?php

namespace App\Filament\Resources;

use App\Enums\ColumnCategory;
use App\Enums\SlaTimerStatus;
use App\Exceptions\Helpdesk\HelpdeskException;
use App\Filament\Resources\TicketResource\Pages;
use App\Filament\Resources\TicketResource\RelationManagers\ActivitiesRelationManager;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CustomFields\CustomFieldService;
use App\Services\Tickets\TicketService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Colors\Color;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;
use Throwable;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Atendimento';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'ticket';

    protected static ?string $pluralModelLabel = 'tickets';

    protected static ?string $recordTitleAttribute = 'subject';

    // ================================================================== Acesso

    /**
     * N1 precisa de `viewAny` (o Filament exige em toda página do Resource, inclusive
     * a View do ticket aberto pelo Kanban), mas não deve ver a lista nem buscar.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return ! auth()->user()?->isKanbanOnly();
    }

    public static function canGloballySearch(): bool
    {
        return parent::canGloballySearch() && ! auth()->user()?->isKanbanOnly();
    }

    // ================================================================== Query

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->visibleTo(auth()->user());
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'subject', 'contact.name', 'company.name'];
    }

    public static function getGlobalSearchResultTitle(\Illuminate\Database\Eloquent\Model $record): string
    {
        return $record->number.' · '.$record->subject;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Ticket::query()->open()->where('assignee_id', auth()->id())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Abertos atribuídos a você';
    }

    // ================================================================== Formulário

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Forms\Components\Section::make('Solicitação')
                            ->schema([
                                Forms\Components\TextInput::make('subject')
                                    ->label('Assunto')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\RichEditor::make('description')
                                    ->label('Descrição')
                                    ->disableToolbarButtons(['attachFiles'])
                                    ->columnSpanFull(),
                            ]),

                        // Campos dinâmicos (EAV) — reconstruídos ao trocar o tipo
                        Forms\Components\Section::make(fn (Get $get) => TicketType::query()->find($get('ticket_type_id'))?->name ?? 'Campos do tipo')
                            ->description('Campos específicos do tipo de ticket')
                            ->icon('heroicon-o-adjustments-horizontal')
                            ->columns(2)
                            ->visible(fn (Get $get) => app(CustomFieldService::class)->fieldsForType($get('ticket_type_id'))->isNotEmpty())
                            ->schema(fn (Get $get): array => app(CustomFieldService::class)->formComponents($get('ticket_type_id')))
                            ->key('custom-fields'),
                    ]),

                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Forms\Components\Section::make('Classificação')
                            ->schema([
                                Forms\Components\Select::make('ticket_type_id')
                                    ->label('Tipo')
                                    ->options(fn () => TicketType::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(fn (Set $set) => $set('custom_fields', [])),
                                Forms\Components\Select::make('ticket_priority_id')
                                    ->label('Prioridade')
                                    ->options(fn () => TicketPriority::query()->orderBy('level')->pluck('name', 'id'))
                                    ->default(fn () => TicketPriority::default()?->id)
                                    ->required()
                                    ->disabled(fn (?Ticket $record) => $record && ! Gate::allows('changePriority', $record))
                                    ->dehydrated(fn (?Ticket $record) => ! $record || Gate::allows('changePriority', $record)),
                                Forms\Components\Select::make('channel')
                                    ->label('Canal')
                                    ->options(fn () => config('helpdesk.channels'))
                                    ->default(fn () => array_key_first(config('helpdesk.channels')))
                                    ->required(),
                            ]),

                        Forms\Components\Section::make('Fluxo')
                            ->schema([
                                Forms\Components\Select::make('board_id')
                                    ->label('Quadro')
                                    ->options(fn () => Board::query()->accessibleBy(auth()->user())->where('is_active', true)->pluck('name', 'id'))
                                    ->default(fn () => Board::query()->where('is_default', true)->value('id'))
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (Set $set) => $set('board_column_id', null))
                                    ->disabledOn('edit'),
                                Forms\Components\Select::make('board_column_id')
                                    ->label('Coluna')
                                    ->options(fn (Get $get) => BoardColumn::query()
                                        ->where('board_id', $get('board_id'))
                                        ->orderBy('position')
                                        ->pluck('name', 'id'))
                                    ->placeholder('Coluna inicial do quadro')
                                    ->disabled(fn (?Ticket $record) => $record && ! Gate::allows('move', $record)),
                                Forms\Components\Select::make('department_id')
                                    ->label('Departamento')
                                    ->relationship('department', 'name')
                                    ->searchable()
                                    ->preload(),
                                Forms\Components\Select::make('team_id')
                                    ->label('Equipe')
                                    ->options(fn (Get $get) => Team::query()
                                        ->where('is_active', true)
                                        ->when($get('department_id'), fn ($query, $id) => $query->where('department_id', $id))
                                        ->orderBy('tier')
                                        ->get()
                                        ->mapWithKeys(fn (Team $t) => [$t->id => "N{$t->tier} · {$t->name}"]))
                                    ->searchable()
                                    ->live(),
                                Forms\Components\Select::make('assignee_id')
                                    ->label('Responsável')
                                    ->options(fn (Get $get) => static::assigneeOptions($get('team_id')))
                                    ->searchable()
                                    ->placeholder('Distribuição automática')
                                    ->helperText(fn (?Ticket $record) => $record ? null : 'Vazio = round-robin da equipe')
                                    ->disabled(fn (?Ticket $record) => ! static::canAssignOthers($record)),
                            ]),

                        Forms\Components\Section::make('Cliente')
                            ->schema([
                                Forms\Components\Select::make('contact_id')
                                    ->label('Contato')
                                    ->relationship('contact', 'name')
                                    ->searchable(['name', 'email', 'phone'])
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, ?string $state) {
                                        if ($state) {
                                            $set('client_company_id', \App\Models\Contact::query()->whereKey($state)->value('client_company_id'));
                                        }
                                    })
                                    ->createOptionForm(ContactResource::formSchema()),
                                Forms\Components\Select::make('client_company_id')
                                    ->label('Empresa')
                                    ->relationship('company', 'name')
                                    ->searchable()
                                    ->preload(),
                            ]),
                    ]),
            ]);
    }

    // ================================================================== Tabela

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'column', 'priority', 'assignee', 'team', 'company', 'contact', 'type',
                'slaTimers' => fn ($query) => $query->where('status', '!=', SlaTimerStatus::Cancelled->value)->with('schedule'),
            ]))
            ->defaultSort('created_at', 'desc')
            ->poll('60s')
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('#')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('sm'),
                Tables\Columns\TextColumn::make('subject')
                    ->label('Assunto')
                    ->searchable()
                    ->limit(60)
                    ->weight('bold')
                    ->description(fn (Ticket $record) => collect([$record->company?->name, $record->contact?->name])->filter()->implode(' · ') ?: null),
                Tables\Columns\TextColumn::make('column.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Ticket $record) => Color::hex($record->column?->color ?? '#71717a')),
                Tables\Columns\TextColumn::make('priority.name')
                    ->label('Prioridade')
                    ->badge()
                    ->color(fn (Ticket $record) => Color::hex($record->priority?->color ?? '#71717a'))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        TicketPriority::query()->select('level')->whereColumn('ticket_priorities.id', 'tickets.ticket_priority_id'),
                        $direction
                    )),
                Tables\Columns\ViewColumn::make('sla')
                    ->label('SLA')
                    ->view('filament.tables.columns.sla-timer'),
                Tables\Columns\TextColumn::make('assignee.name')
                    ->label('Responsável')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('team.name')
                    ->label('Equipe')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('type.name')
                    ->label('Tipo')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('channel')
                    ->label('Canal')
                    ->formatStateUsing(fn (?string $state) => config("helpdesk.channels.{$state}", $state))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Aberto')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('open')
                    ->label('Situação')
                    ->placeholder('Todos')
                    ->trueLabel('Abertos')
                    ->falseLabel('Resolvidos')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('resolved_at'),
                        false: fn (Builder $query) => $query->whereNotNull('resolved_at'),
                    ),
                Tables\Filters\SelectFilter::make('board_column_id')
                    ->label('Coluna')
                    ->multiple()
                    ->options(fn () => BoardColumn::query()->with('board:id,name')->orderBy('board_id')->orderBy('position')->get()
                        ->mapWithKeys(fn (BoardColumn $c) => [$c->id => $c->board->name.' › '.$c->name])),
                Tables\Filters\SelectFilter::make('ticket_priority_id')
                    ->label('Prioridade')
                    ->multiple()
                    ->relationship('priority', 'name'),
                Tables\Filters\SelectFilter::make('team_id')
                    ->label('Equipe')
                    ->multiple()
                    ->relationship('team', 'name'),
                Tables\Filters\SelectFilter::make('assignee')
                    ->label('Responsável')
                    ->options(fn () => ['none' => '— Sem responsável —', 'me' => '★ Eu'] + User::query()->agents()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'none' => $query->whereNull('assignee_id'),
                        'me' => $query->where('assignee_id', auth()->id()),
                        default => $query->where('assignee_id', $data['value']),
                    }),
                Tables\Filters\SelectFilter::make('ticket_type_id')
                    ->label('Tipo')
                    ->relationship('type', 'name'),
                Tables\Filters\SelectFilter::make('channel')
                    ->label('Canal')
                    ->options(fn () => config('helpdesk.channels')),
                Tables\Filters\SelectFilter::make('sla')
                    ->label('SLA')
                    ->options(['breached' => 'Violado', 'at_risk' => 'Em alerta', 'paused' => 'Pausado'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'breached' => $query->whereHas('slaTimers', fn ($t) => $t->whereNotNull('breached_at')),
                        'at_risk' => $query->whereHas('slaTimers', fn ($t) => $t->where('status', SlaTimerStatus::Running->value)
                            ->whereNotNull('warned_at')->whereNull('breached_at')),
                        'paused' => $query->whereHas('slaTimers', fn ($t) => $t->where('status', SlaTimerStatus::Paused->value)),
                        default => $query,
                    }),
                Tables\Filters\TrashedFilter::make()
                    ->visible(fn () => Gate::allows('restoreAny', Ticket::class)),
            ])
            ->filtersFormColumns(3)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    static::assumeAction(Tables\Actions\Action::class),
                    static::moveAction(Tables\Actions\Action::class),
                    Tables\Actions\DeleteAction::make()
                        ->using(fn (Ticket $record) => app(TicketService::class)->delete($record, auth()->user())),
                    Tables\Actions\RestoreAction::make()
                        ->using(fn (Ticket $record) => app(TicketService::class)->restore($record, auth()->user())),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('bulkAssign')
                        ->label('Atribuir')
                        ->icon('heroicon-o-user-plus')
                        ->visible(fn () => auth()->user()->can('tickets.assign'))
                        ->form([
                            Forms\Components\Select::make('assignee_id')->label('Responsável')->required()->searchable()
                                ->options(fn () => User::query()->agents()->orderBy('name')->pluck('name', 'id')),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $user = User::query()->findOrFail($data['assignee_id']);
                            $records->each(fn (Ticket $t) => Gate::allows('assign', $t) && app(TicketService::class)->assign($t, $user, auth()->user()));
                            Notification::make()->title('Tickets atribuídos a '.$user->name)->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('bulkMove')
                        ->label('Mover')
                        ->icon('heroicon-o-arrows-right-left')
                        ->visible(fn () => auth()->user()->can('tickets.move'))
                        ->form([
                            Forms\Components\Select::make('board_column_id')->label('Coluna')->required()
                                ->options(fn () => BoardColumn::query()->with('board:id,name')->orderBy('board_id')->orderBy('position')->get()
                                    ->mapWithKeys(fn (BoardColumn $c) => [$c->id => $c->board->name.' › '.$c->name])),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $column = BoardColumn::query()->findOrFail($data['board_column_id']);
                            $failed = 0;
                            foreach ($records as $ticket) {
                                try {
                                    Gate::authorize('move', $ticket);
                                    app(TicketService::class)->moveToColumn($ticket, $column, null, auth()->user());
                                } catch (Throwable) {
                                    $failed++;
                                }
                            }
                            Notification::make()
                                ->title($failed ? "{$failed} ticket(s) não puderam ser movidos" : 'Tickets movidos para '.$column->name)
                                ->status($failed ? 'warning' : 'success')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->using(fn (Collection $records) => $records->each(
                            fn (Ticket $t) => Gate::allows('delete', $t) && app(TicketService::class)->delete($t, auth()->user())
                        )),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    // ================================================================== Ações reutilizáveis (tabela + página)

    /**
     * @template T of \Filament\Actions\Action|\Filament\Tables\Actions\Action
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function assumeAction(string $class)
    {
        return $class::make('assume')
            ->label('Assumir')
            ->icon('heroicon-o-hand-raised')
            ->color('primary')
            ->visible(fn (Ticket $record) => $record->assignee_id !== auth()->id() && Gate::allows('assignToSelf', $record))
            ->action(function (Ticket $record) {
                app(TicketService::class)->assign($record, auth()->user(), auth()->user());
                Notification::make()->title("{$record->number} agora é seu")->success()->send();
            });
    }

    /**
     * @template T of \Filament\Actions\Action|\Filament\Tables\Actions\Action
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function moveAction(string $class)
    {
        return $class::make('move')
            ->label('Mover')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (Ticket $record) => Gate::allows('move', $record))
            ->fillForm(fn (Ticket $record) => ['board_column_id' => $record->board_column_id])
            ->form([
                Forms\Components\Select::make('board_column_id')
                    ->label('Coluna')
                    ->required()
                    ->options(fn (Ticket $record) => $record->board->columns()->pluck('name', 'id')),
            ])
            ->action(function (Ticket $record, array $data) {
                try {
                    app(TicketService::class)->moveToColumn($record, BoardColumn::query()->findOrFail($data['board_column_id']), null, auth()->user());
                    Notification::make()->title('Ticket movido')->success()->send();
                } catch (HelpdeskException $e) {
                    Notification::make()->title('Não foi possível mover')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function resolveAction(string $class)
    {
        return $class::make('resolve')
            ->label('Resolver')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Ticket $record) => $record->isOpen() && Gate::allows('move', $record)
                && $record->board->columns()->where('category', ColumnCategory::Resolved->value)->exists())
            ->action(function (Ticket $record) {
                app(TicketService::class)->resolve($record, auth()->user());
                Notification::make()->title("{$record->number} resolvido")->success()->send();
            });
    }

    // ================================================================== Helpers

    /** @return array<int, string> */
    public static function assigneeOptions(?int $teamId): array
    {
        $query = $teamId
            ? Team::query()->find($teamId)?->members()->where('users.is_active', true)
            : User::query()->agents();

        return ($query ?? User::query()->agents())->orderBy('name')->pluck('users.name', 'users.id')->all();
    }

    public static function canAssignOthers(?Ticket $record): bool
    {
        $user = auth()->user();

        return $record ? Gate::allows('assign', $record) : $user->can('tickets.assign');
    }

    public static function getRelations(): array
    {
        return [
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTickets::route('/'),
            'create' => Pages\CreateTicket::route('/create'),
            'view' => Pages\ViewTicket::route('/{record}'),
            'edit' => Pages\EditTicket::route('/{record}/edit'),
        ];
    }
}
