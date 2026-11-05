<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;

final class AutomationContext
{
    public function __construct(
        public readonly string $event,
        public readonly array $payload,
        public readonly int $depth,
        public readonly ?AutomationRule $rule = null,
    ) {}

    public function forRule(AutomationRule $rule): self
    {
        return new self($this->event, $this->payload, $this->depth, $rule);
    }
}
