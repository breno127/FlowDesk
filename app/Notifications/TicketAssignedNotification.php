<?php

namespace App\Notifications;

use App\Models\Ticket;

class TicketAssignedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public bool $automatic = false)
    {
        parent::__construct($ticket);
    }

    protected function title(): string
    {
        return ($this->automatic ? 'Ticket distribuído para você: ' : 'Ticket atribuído a você: ').$this->ticket->number;
    }

    protected function body(): ?string
    {
        return $this->ticket->subject;
    }

    protected function icon(): string
    {
        return 'heroicon-o-user-plus';
    }
}
