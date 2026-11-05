<?php

namespace App\Repositories\Contracts;

use App\Models\TicketSlaTimer;
use Carbon\CarbonInterface;

interface SlaTimerRepositoryInterface
{
    /** @param callable(TicketSlaTimer): void $callback */
    public function eachDueForWarning(CarbonInterface $at, int $chunk, callable $callback): void;

    /** @param callable(TicketSlaTimer): void $callback */
    public function eachDueForBreach(CarbonInterface $at, int $chunk, callable $callback): void;

    /** Marca o alerta somente se ainda não marcado. Retorna true se este processo marcou. */
    public function markWarned(TicketSlaTimer $timer, CarbonInterface $at): bool;

    /** Marca a violação somente se ainda não marcada. Retorna true se este processo marcou. */
    public function markBreached(TicketSlaTimer $timer, CarbonInterface $at): bool;
}
