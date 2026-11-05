<?php

namespace App\Events;

/** payload: ['previous_assignee_id' => ?int, 'assignee_id' => ?int, 'automatic' => bool] */
class TicketAssigned extends TicketEvent
{
    public function automationEvent(): string
    {
        return 'ticket.assigned';
    }
}
