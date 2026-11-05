<?php

namespace App\Events;

class TicketCreated extends TicketEvent
{
    public function automationEvent(): string
    {
        return 'ticket.created';
    }
}
