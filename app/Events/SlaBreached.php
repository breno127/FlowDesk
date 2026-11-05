<?php

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketSlaTimer;

class SlaBreached extends TicketEvent
{
    public function __construct(Ticket $ticket, public TicketSlaTimer $timer)
    {
        parent::__construct($ticket, null, [
            'sla' => ['type' => $timer->type->value, 'timer_id' => $timer->id],
        ]);
    }

    public function automationEvent(): string
    {
        return 'sla.breached';
    }
}
