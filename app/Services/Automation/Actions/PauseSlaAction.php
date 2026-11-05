<?php

namespace App\Services\Automation\Actions;

use App\Contracts\AutomationAction;
use App\Models\Ticket;
use App\Services\Automation\AutomationContext;
use App\Services\Sla\SlaCalculationService;
use Filament\Forms\Components\TextInput;

class PauseSlaAction implements AutomationAction
{
    public function __construct(private readonly SlaCalculationService $sla) {}

    public static function label(): string
    {
        return 'Pausar SLA';
    }

    public static function schema(): array
    {
        return [
            TextInput::make('reason')->label('Motivo')->maxLength(255),
        ];
    }

    public function execute(Ticket $ticket, array $data, AutomationContext $context): string
    {
        $this->sla->pause($ticket, $data['reason'] ?? ('Regra: '.$context->rule?->name));

        return 'SLA pausado';
    }
}
