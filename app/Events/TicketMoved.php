<?php

namespace App\Events;

/** payload: ['from_column_id' => int, 'to_column_id' => int] */
class TicketMoved extends TicketEvent
{
    public function automationEvent(): string
    {
        return 'ticket.moved';
    }
}
