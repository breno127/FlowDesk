<?php

namespace App\Filament\Widgets;

use App\Models\Ticket;
use App\Models\User;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Produtividade: tickets resolvidos por responsável no período.
 *
 * Tudo é calculado no banco com withCount/withAvg (uma única query, sem N+1).
 * "Resolvido" = resolved_at dentro do período — o TicketService preenche ao
 * entrar numa coluna de categoria Resolvido/Fechado e limpa se reabrir.
 */
class AgentResolutionWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Resolução por agente';

    private const PERIODS = [
        'today' => 'Hoje',
        'week' => 'Esta semana',
        'month' => 'Mês atual',
        'last_month' => 'Mês anterior',
        'year' => 'Este ano',
        'all' => 'Total geral',
    ];

    public static function canView(): bool
    {
        $user = auth()->user();

        return ! $user->isKanbanOnly()
            && $user->canAny(['dashboards.view_own', 'dashboards.view_others', 'dashboards.view_operation']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->baseQuery())
            ->defaultSort('resolved_count', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Nenhum agente encontrado')
            ->columns([
                Tables\Columns\TextColumn::make('rank')
                    ->label('#')
                    ->rowIndex()
                    ->width('3rem'),
                Tables\Columns\ImageColumn::make('avatar')
                    ->label('')
                    ->circular()
                    // Mesmo provedor de avatar do menu do usuário (upload ou iniciais)
                    ->state(fn (User $record) => filament()->getUserAvatarUrl($record))
                    ->width('2.5rem'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Agente')
                    ->searchable()
                    ->description(fn (User $record) => $record->teams->pluck('name')->implode(', ') ?: null),
                Tables\Columns\TextColumn::make('resolved_count')
                    ->label('Resolvidos')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray')
                    ->sortable()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('open_count')
                    ->label('Em aberto agora')
                    ->sortable()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('avg_resolution_hours')
                    ->label('Tempo médio')
                    ->state(fn (User $record) => $record->avg_resolution_seconds)
                    ->formatStateUsing(fn ($state) => $this->humanDuration($state))
                    ->placeholder('—')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('csat_avg')
                    ->label('CSAT')
                    ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 1, ',', '').' ★' : null)
                    ->placeholder('—')
                    ->sortable()
                    ->alignCenter(),
            ])
            ->filters([
                // A query base lê o estado deste filtro; aqui ele só precisa existir.
                Tables\Filters\SelectFilter::make('period')
                    ->label('Período')
                    ->options(self::PERIODS)
                    ->default('month')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query) => $query),
                Tables\Filters\SelectFilter::make('team')
                    ->label('Equipe')
                    ->relationship('teams', 'name')
                    ->visible(fn () => $this->canSeeOthers()),
            ])
            ->filtersFormColumns(2);
    }

    private function baseQuery(): Builder
    {
        [$from, $to] = $this->periodRange($this->tableFilters['period']['value'] ?? 'month');

        $inPeriod = fn (Builder $tickets) => $tickets
            ->whereNotNull('resolved_at')
            ->when($from, fn (Builder $q) => $q->whereBetween('resolved_at', [$from, $to]));

        // Duração em segundos portátil entre SQLite (testes) e MySQL (produção)
        $durationSql = $this->getTableQueryDriver() === 'sqlite'
            ? '(julianday(resolved_at) - julianday(created_at)) * 86400'
            : 'TIMESTAMPDIFF(SECOND, created_at, resolved_at)';

        return User::query()
            ->agents()
            ->with('teams:id,name')
            ->when(! $this->canSeeOthers(), fn (Builder $q) => $q->whereKey(auth()->id()))
            ->withCount([
                'assignedTickets as resolved_count' => $inPeriod,
                'assignedTickets as open_count' => fn (Builder $q) => $q->whereNull('resolved_at'),
            ])
            ->withAvg(['assignedTickets as csat_avg' => $inPeriod], 'csat_score')
            ->addSelect(['avg_resolution_seconds' => Ticket::query()
                ->selectRaw("AVG({$durationSql})")
                ->whereColumn('assignee_id', 'users.id')
                ->tap($inPeriod),
            ]);
    }

    private function canSeeOthers(): bool
    {
        return auth()->user()->canAny(['dashboards.view_others', 'dashboards.view_operation']);
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function periodRange(?string $period): array
    {
        $now = now();

        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'all' => [null, null],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    private function getTableQueryDriver(): string
    {
        return (new User)->getConnection()->getDriverName();
    }

    private function humanDuration(mixed $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        $minutes = (int) round(((float) $seconds) / 60);

        return match (true) {
            $minutes < 60 => "{$minutes} min",
            $minutes < 1440 => intdiv($minutes, 60).'h '.($minutes % 60).'min',
            default => intdiv($minutes, 1440).'d '.intdiv($minutes % 1440, 60).'h',
        };
    }
}
