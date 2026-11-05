<?php

namespace App\Events;

class TicketResolved extends TicketEvent
{
    public function automationEvent(): string
    {
        return 'ticket.resolved';
    }
}
