<?php

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketMessage;

class TicketMessageCreated extends TicketEvent
{
    public function __construct(Ticket $ticket, public TicketMessage $message, ?int $actorId = null)
    {
        parent::__construct($ticket, $actorId, [
            'message_id' => $message->id,
            'message' => [
                'visibility' => $message->visibility->value,
                'direction' => $message->direction->value,
                'channel' => $message->channel,
            ],
        ]);
    }

    public function automationEvent(): string
    {
        return 'message.created';
    }
}
