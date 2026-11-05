<?php

namespace App\Listeners;

use App\Contracts\AutomationTriggerEvent;
use App\Services\Automation\AutomationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Encaminha todo evento de domínio ao motor de automação, em background.
 */
class TriggerAutomationRules implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public function __construct(private readonly AutomationEngine $engine) {}

    public function viaQueue(): string
    {
        return config('helpdesk.automation.queue');
    }

    public function handle(AutomationTriggerEvent $event): void
    {
        $ticket = $event->ticket();

        if (! $ticket->exists || $ticket->trashed()) {
            return;
        }

        $this->engine->run(
            $event->automationEvent(),
            $ticket,
            $event->automationPayload(),
            $event->depth(),
        );
    }
}
