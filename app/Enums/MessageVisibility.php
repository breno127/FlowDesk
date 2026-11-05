<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MessageVisibility: string implements HasColor, HasLabel
{
    case Public = 'public';
    case Internal = 'internal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Public => 'Pública',
            self::Internal => 'Nota interna',
        };
    }

    public function getColor(): string
    {
        return $this === self::Internal ? 'warning' : 'info';
    }
}
