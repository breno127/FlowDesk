<?php

namespace App\Services\Sla;

use App\Enums\SlaState;
use App\Enums\SlaTimerType;
use Carbon\CarbonImmutable;

/**
 * Fotografia imutável de um timer num instante — consumida pelo Kanban,
 * pela tabela do Filament e pela API.
 */
final readonly class SlaSnapshot
{
    public function __construct(
        public SlaTimerType $type,
        public SlaState $state,
        public int $targetSeconds,
        public int $elapsedSeconds,
        public int $remainingSeconds,
        public float $percent,
        public ?CarbonImmutable $dueAt,
        public bool $ticking,
        public int $warningPercent = 75,
        public int $criticalPercent = 90,
    ) {}

    public function humanRemaining(): string
    {
        $seconds = abs($this->remainingSeconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $text = match (true) {
            $days > 0 => sprintf('%dd %02dh', $days, $hours),
            $hours > 0 => sprintf('%dh %02dm', $hours, $minutes),
            default => sprintf('%dm', max($minutes, $seconds > 0 ? 1 : 0)),
        };

        return $this->remainingSeconds < 0 ? '-'.$text : $text;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->type->getLabel(),
            'state' => $this->state->value,
            'state_label' => $this->state->getLabel(),
            'target_seconds' => $this->targetSeconds,
            'elapsed_seconds' => $this->elapsedSeconds,
            'remaining_seconds' => $this->remainingSeconds,
            'remaining_human' => $this->humanRemaining(),
            'percent' => round($this->percent, 1),
            'due_at' => $this->dueAt?->toIso8601String(),
            'ticking' => $this->ticking,
            'warning_percent' => $this->warningPercent,
            'critical_percent' => $this->criticalPercent,
        ];
    }
}
