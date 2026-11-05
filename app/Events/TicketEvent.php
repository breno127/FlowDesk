<?php

namespace App\Events;

use App\Contracts\AutomationTriggerEvent;
use App\Models\Ticket;
use App\Support\AutomationDepth;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base dos eventos de domínio do ticket. Despachados somente após o COMMIT
 * da transação, então listeners enfileirados nunca leem estado não persistido.
 */
abstract class TicketEvent implements AutomationTriggerEvent, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public int $depth;

    public function __construct(
        public Ticket $ticket,
        public ?int $actorId = null,
        public array $payload = [],
    ) {
        $this->depth = AutomationDepth::current();
    }

    abstract public function automationEvent(): string;

    public function ticket(): Ticket
    {
        return $this->ticket;
    }

    public function automationPayload(): array
    {
        return $this->payload + ['actor_id' => $this->actorId];
    }

    public function depth(): int
    {
        return $this->depth;
    }
}
