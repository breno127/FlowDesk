<?php

namespace App\Repositories\Eloquent;

use App\Enums\SlaTimerStatus;
use App\Models\TicketSlaTimer;
use App\Repositories\Contracts\SlaTimerRepositoryInterface;
use Carbon\CarbonInterface;

class SlaTimerRepository implements SlaTimerRepositoryInterface
{
    public function eachDueForWarning(CarbonInterface $at, int $chunk, callable $callback): void
    {
        TicketSlaTimer::query()
            ->with(['ticket', 'schedule'])
            ->where('status', SlaTimerStatus::Running->value)
            ->whereNull('warned_at')
            ->whereNull('breached_at')
            ->whereNotNull('warning_due_at')
            ->where('warning_due_at', '<=', $at)
            ->chunkById($chunk, fn ($timers) => $timers->each($callback));
    }

    public function eachDueForBreach(CarbonInterface $at, int $chunk, callable $callback): void
    {
        TicketSlaTimer::query()
            ->with(['ticket', 'schedule'])
            ->where('status', SlaTimerStatus::Running->value)
            ->whereNull('breached_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $at)
            ->chunkById($chunk, fn ($timers) => $timers->each($callback));
    }

    public function markWarned(TicketSlaTimer $timer, CarbonInterface $at): bool
    {
        return TicketSlaTimer::query()
            ->whereKey($timer->getKey())
            ->where('status', SlaTimerStatus::Running->value)
            ->whereNull('warned_at')
            ->update(['warned_at' => $at, 'updated_at' => now()]) > 0;
    }

    public function markBreached(TicketSlaTimer $timer, CarbonInterface $at): bool
    {
        return TicketSlaTimer::query()
            ->whereKey($timer->getKey())
            ->where('status', SlaTimerStatus::Running->value)
            ->whereNull('breached_at')
            ->update([
                'breached_at' => $at,
                'warned_at' => $timer->warned_at ?? $at,
                'updated_at' => now(),
            ]) > 0;
    }
}
