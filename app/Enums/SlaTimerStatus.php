<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SlaTimerStatus: string implements HasLabel
{
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Running => 'Em contagem',
            self::Paused => 'Pausado',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Running || $this === self::Paused;
    }
}
