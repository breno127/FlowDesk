<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Str;

class MentionedInTicketNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public TicketMessage $message, public ?User $author = null)
    {
        parent::__construct($ticket);
    }

    protected function title(): string
    {
        return sprintf('%s mencionou você em %s', $this->author?->name ?? 'Automação', $this->ticket->number);
    }

    protected function body(): ?string
    {
        return Str::limit(strip_tags($this->message->body), 180);
    }

    protected function icon(): string
    {
        return 'heroicon-o-at-symbol';
    }
}
