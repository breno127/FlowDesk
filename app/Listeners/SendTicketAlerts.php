<?php

namespace App\Listeners;

use App\Events\SlaBreached;
use App\Events\SlaWarningReached;
use App\Events\TicketAssigned;
use App\Models\User;
use App\Notifications\SlaAlertNotification;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;

/**
 * Notificações padrão do sistema (independentes das regras de automação):
 * - responsável recebe aviso de atribuição;
 * - responsável + líderes da equipe recebem alerta/violação de SLA.
 */
class SendTicketAlerts
{
    public function onAssigned(TicketAssigned $event): void
    {
        $assigneeId = $event->payload['assignee_id'] ?? null;

        // Não notifica quem atribuiu a si mesmo
        if (! $assigneeId || $assigneeId === $event->actorId) {
            return;
        }

        User::query()->agents()->find($assigneeId)
            ?->notify(new TicketAssignedNotification($event->ticket, (bool) ($event->payload['automatic'] ?? false)));
    }

    public function onSlaWarning(SlaWarningReached $event): void
    {
        $this->notifySla($event->ticket, $event->timer, breached: false);
    }

    public function onSlaBreached(SlaBreached $event): void
    {
        $this->notifySla($event->ticket, $event->timer, breached: true);
    }

    private function notifySla($ticket, $timer, bool $breached): void
    {
        $recipients = collect();

        if ($ticket->assignee_id) {
            $recipients->push($ticket->assignee);
        }

        if ($ticket->team) {
            $recipients = $recipients->merge(
                $ticket->team->members()->wherePivot('is_lead', true)->where('users.is_active', true)->get()
            );
        }

        $recipients = $recipients->filter()->unique('id');

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new SlaAlertNotification($ticket, $timer, $breached));
        }
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            TicketAssigned::class => 'onAssigned',
            SlaWarningReached::class => 'onSlaWarning',
            SlaBreached::class => 'onSlaBreached',
        ];
    }
}
