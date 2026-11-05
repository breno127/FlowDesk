<?php

namespace App\Notifications;

use App\Models\Ticket;

class AutomationAlertNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public string $alertTitle, public ?string $alertBody = null)
    {
        parent::__construct($ticket);
    }

    protected function title(): string
    {
        return $this->alertTitle ?: $this->ticket->number;
    }

    protected function body(): ?string
    {
        return $this->alertBody ?: $this->ticket->subject;
    }

    protected function icon(): string
    {
        return 'heroicon-o-bolt';
    }
}
