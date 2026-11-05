<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RoutingStrategy: string implements HasLabel
{
    case LoadBalanced = 'load_balanced';
    case RoundRobin = 'round_robin';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::LoadBalanced => 'Balanceada por carga (peso)',
            self::RoundRobin => 'Round-robin puro',
            self::Manual => 'Manual',
        };
    }
}
