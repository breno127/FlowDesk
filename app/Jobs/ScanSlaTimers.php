<?php

namespace App\Jobs;

use App\Services\Sla\SlaCalculationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Executado a cada minuto pelo scheduler. Marca alertas/violações de SLA cujo
 * instante projetado já passou e dispara os eventos correspondentes.
 */
class ScanSlaTimers implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 90;

    public function __construct()
    {
        $this->onQueue(config('helpdesk.sla.queue'));
    }

    public function handle(SlaCalculationService $sla): void
    {
        $result = $sla->scan();

        if ($result['warnings'] || $result['breaches']) {
            Log::info('Varredura de SLA concluída.', $result);
        }
    }
}
