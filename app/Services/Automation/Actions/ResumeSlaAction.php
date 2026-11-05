<?php

namespace App\Services\Automation\Actions;

use App\Contracts\AutomationAction;
use App\Models\Ticket;
use App\Services\Automation\AutomationContext;
use App\Services\Sla\SlaCalculationService;

class ResumeSlaAction implements AutomationAction
{
    public function __construct(private readonly SlaCalculationService $sla) {}

    public static function label(): string
    {
        return 'Retomar SLA';
    }

    public static function schema(): array
    {
        return [];
    }

    public function execute(Ticket $ticket, array $data, AutomationContext $context): string
    {
        $this->sla->resume($ticket);

        return 'SLA retomado';
    }
}
