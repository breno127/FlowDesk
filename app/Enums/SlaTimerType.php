<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SlaTimerType: string implements HasLabel
{
    case FirstResponse = 'first_response';
    case Resolution = 'resolution';

    public function getLabel(): string
    {
        return match ($this) {
            self::FirstResponse => '1ª resposta',
            self::Resolution => 'Solução',
        };
    }

    /** Coluna de sla_targets com a meta em minutos. */
    public function targetColumn(): string
    {
        return match ($this) {
            self::FirstResponse => 'first_response_minutes',
            self::Resolution => 'resolution_minutes',
        };
    }
}
