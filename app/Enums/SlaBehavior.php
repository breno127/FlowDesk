<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SlaBehavior: string implements HasLabel
{
    case Run = 'run';
    case Pause = 'pause';
    case Stop = 'stop';

    public function getLabel(): string
    {
        return match ($this) {
            self::Run => 'Contar SLA',
            self::Pause => 'Pausar SLA',
            self::Stop => 'Encerrar SLA',
        };
    }
}
