<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Pages\KanbanBoard;
use App\Filament\Resources\TicketResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('kanban')
                ->label('Kanban')
                ->icon('heroicon-o-view-columns')
                ->color('gray')
                ->url(KanbanBoard::getUrl())
                ->visible(fn () => KanbanBoard::canAccess()),
            Actions\CreateAction::make()->label('Novo ticket'),
        ];
    }
}
