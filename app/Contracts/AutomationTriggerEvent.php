<?php

namespace App\Contracts;

use App\Models\Ticket;

/**
 * Qualquer evento que possa disparar regras de automação.
 */
interface AutomationTriggerEvent
{
    /** Chave do evento (ver config helpdesk.automation.events). */
    public function automationEvent(): string;

    public function ticket(): Ticket;

    /** Contexto adicional disponível às condições (changes, from_column_id, message...). */
    public function automationPayload(): array;

    /** Profundidade de cascata em que o evento foi gerado. */
    public function depth(): int;
}
