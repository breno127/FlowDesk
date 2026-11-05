<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Categoria semântica de uma coluna do Kanban. As colunas têm nome livre,
 * mas a categoria garante métricas e regras consistentes entre quadros.
 */
enum ColumnCategory: string implements HasColor, HasLabel
{
    case New = 'new';
    case Open = 'open';
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Novo',
            self::Open => 'Em andamento',
            self::Pending => 'Pendente',
            self::Resolved => 'Resolvido',
            self::Closed => 'Fechado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Open => 'primary',
            self::Pending => 'warning',
            self::Resolved => 'success',
            self::Closed => 'gray',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Resolved, self::Closed], true);
    }

    /** @return list<string> */
    public static function finalValues(): array
    {
        return [self::Resolved->value, self::Closed->value];
    }
}
