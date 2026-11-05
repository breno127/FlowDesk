<?php

namespace App\Events;

/** payload: ['changes' => [campo => ['old' => x, 'new' => y]]] */
class TicketUpdated extends TicketEvent
{
    public function automationEvent(): string
    {
        return 'ticket.updated';
    }
}
