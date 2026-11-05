<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado visual derivado de um timer (cor no card do Kanban e na tabela).
 */
enum SlaState: string implements HasColor, HasLabel
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';
    case Breached = 'breached';
    case Paused = 'paused';
    case Met = 'met';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ok => 'No prazo',
            self::Warning => 'Atenção',
            self::Critical => 'Crítico',
            self::Breached => 'Violado',
            self::Paused => 'Pausado',
            self::Met => 'Cumprido',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok, self::Met => 'success',
            self::Warning => 'warning',
            self::Critical, self::Breached => 'danger',
            self::Paused => 'gray',
        };
    }
}
