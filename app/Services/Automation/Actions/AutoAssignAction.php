<?php

namespace App\Services\Automation\Actions;

use App\Contracts\AutomationAction;
use App\Models\Ticket;
use App\Services\Automation\AutomationContext;
use App\Services\Tickets\TicketService;
use Filament\Forms\Components\Toggle;

class AutoAssignAction implements AutomationAction
{
    public function __construct(private readonly TicketService $tickets) {}

    public static function label(): string
    {
        return 'Distribuir automaticamente (round-robin)';
    }

    public static function schema(): array
    {
        return [
            Toggle::make('only_if_unassigned')->label('Somente se ainda não houver responsável')->default(true),
        ];
    }

    public function execute(Ticket $ticket, array $data, AutomationContext $context): string
    {
        if (($data['only_if_unassigned'] ?? true) && $ticket->assignee_id) {
            return 'Já possui responsável';
        }

        $agent = $this->tickets->autoAssign($ticket);

        return $agent ? 'Atribuído a '.$agent->name : 'Nenhum agente disponível';
    }
}
