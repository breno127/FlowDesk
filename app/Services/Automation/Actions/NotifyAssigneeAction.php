<?php

namespace App\Services\Automation\Actions;

use App\Contracts\AutomationAction;
use App\Models\Ticket;
use App\Notifications\AutomationAlertNotification;
use App\Services\Automation\AutomationContext;
use App\Support\TicketTemplate;

class NotifyAssigneeAction implements AutomationAction
{
    public static function label(): string
    {
        return 'Notificar responsável';
    }

    public static function schema(): array
    {
        return NotifyUsersAction::messageFields();
    }

    public function execute(Ticket $ticket, array $data, AutomationContext $context): string
    {
        $assignee = $ticket->assignee;

        if (! $assignee) {
            return 'Sem responsável — nada a notificar';
        }

        $assignee->notify(new AutomationAlertNotification(
            $ticket,
            TicketTemplate::render($data['title'] ?? '', $ticket),
            TicketTemplate::render($data['message'] ?? '', $ticket),
        ));

        return 'Responsável notificado: '.$assignee->name;
    }
}
