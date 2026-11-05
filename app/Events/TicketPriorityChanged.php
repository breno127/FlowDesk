<?php

namespace App\Events;

/** payload: ['from_priority_id' => int, 'to_priority_id' => int] */
class TicketPriorityChanged extends TicketEvent
{
    public function automationEvent(): string
    {
        return 'ticket.priority_changed';
    }
}
