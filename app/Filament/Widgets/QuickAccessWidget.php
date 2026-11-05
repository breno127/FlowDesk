<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

/**
 * Atalhos para Chat e Agenda. As URLs vêm de config/helpdesk.php
 * (shortcuts.*); enquanto os módulos do passo 4 não existem, o card
 * aparece como "em breve" em vez de um link quebrado.
 */
class QuickAccessWidget extends Widget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.quick-access';

    public static function canView(): bool
    {
        return ! auth()->user()->isKanbanOnly();
    }

    /** @return list<array{label: string, description: string, icon: string, url: ?string}> */
    public function getShortcuts(): array
    {
        return [
            [
                'label' => 'Chat',
                'description' => 'Conversas em tempo real com clientes e equipe',
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'url' => config('helpdesk.shortcuts.chat_url'),
            ],
            [
                'label' => 'Agenda',
                'description' => 'Visitas, reuniões e compromissos agendados',
                'icon' => 'heroicon-o-calendar-days',
                'url' => config('helpdesk.shortcuts.agenda_url'),
            ],
        ];
    }
}
