<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Painel de controle';

    protected static ?string $navigationLabel = 'Painel';

    /** N1 não tem dashboard: a home dele é o Kanban (ver RestrictKanbanOnlyUsers). */
    public static function canAccess(): bool
    {
        return ! (auth()->user()?->isKanbanOnly() ?? true);
    }

    public function getColumns(): int|string|array
    {
        return ['md' => 2, 'xl' => 4];
    }
}
