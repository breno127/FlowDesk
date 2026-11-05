<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketSlaTimer;

class SlaAlertNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public TicketSlaTimer $timer, public bool $breached)
    {
        parent::__construct($ticket);
    }

    protected function title(): string
    {
        return sprintf(
            '%s SLA de %s — %s',
            $this->breached ? 'VIOLADO:' : 'Atenção:',
            mb_strtolower($this->timer->type->getLabel()),
            $this->ticket->number,
        );
    }

    protected function body(): ?string
    {
        $due = $this->timer->due_at?->timezone(config('app.timezone'))->format('d/m H:i');

        return $this->ticket->subject.($due ? ' · vence(u) em '.$due : '');
    }

    protected function icon(): string
    {
        return $this->breached ? 'heroicon-o-fire' : 'heroicon-o-clock';
    }

    protected function status(): string
    {
        return $this->breached ? 'danger' : 'warning';
    }
}
