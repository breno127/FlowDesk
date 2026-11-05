<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\KanbanBoard;
use App\Filament\Resources\TicketResource;
use App\Models\Ticket;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Visão rápida do dia. Todos os números respeitam a visibilidade RBAC
 * (Ticket::visibleTo), então cada agente vê apenas o seu universo.
 */
class TicketOverviewStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return ! auth()->user()->isKanbanOnly();
    }

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $visible = fn () => Ticket::query()->visibleTo($user);

        $today = Carbon::today();
        $openedToday = $visible()->where('created_at', '>=', $today)->count();
        $openedYesterday = $visible()->whereBetween('created_at', [$today->copy()->subDay(), $today])->count();

        // Sparkline: aberturas dos últimos 7 dias
        $trend = collect(range(6, 0))
            ->map(fn (int $daysAgo) => $visible()
                ->whereBetween('created_at', [$today->copy()->subDays($daysAgo), $today->copy()->subDays($daysAgo - 1)])
                ->count())
            ->all();

        $mine = Ticket::query()->open()->where('assignee_id', $user->id);
        $myTotal = (clone $mine)->count();
        $myBreached = (clone $mine)->whereHas('slaTimers', fn ($timers) => $timers->whereNotNull('breached_at')->whereNull('completed_at'))->count();

        $unassigned = $visible()->open()->whereNull('assignee_id')->count();

        return [
            Stat::make('Abertos hoje', $openedToday)
                ->description($this->delta($openedToday, $openedYesterday))
                ->descriptionIcon($openedToday >= $openedYesterday ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->chart($trend)
                ->color('info')
                ->url(TicketResource::getUrl('index')),

            Stat::make('Meus tickets', $myTotal)
                ->description($myBreached > 0 ? "{$myBreached} com SLA violado" : 'Nenhum SLA violado')
                ->descriptionIcon($myBreached > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($myBreached > 0 ? 'danger' : 'success')
                ->url(KanbanBoard::getUrl(['agent' => 'me'])),

            Stat::make('Sem responsável', $unassigned)
                ->description('Na fila aguardando atribuição')
                ->descriptionIcon('heroicon-m-inbox-stack')
                ->color($unassigned > 0 ? 'warning' : 'gray')
                ->url(KanbanBoard::getUrl()),
        ];
    }

    private function delta(int $today, int $yesterday): string
    {
        $diff = $today - $yesterday;

        return match (true) {
            $diff > 0 => "+{$diff} em relação a ontem",
            $diff < 0 => "{$diff} em relação a ontem",
            default => 'Igual a ontem',
        };
    }
}
