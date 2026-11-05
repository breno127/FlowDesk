<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Exceptions\Helpdesk\HelpdeskException;
use App\Filament\Resources\TicketResource;
use App\Services\Tickets\TicketService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTicket extends CreateRecord
{
    protected static string $resource = TicketResource::class;

    protected function afterFill(): void
    {
        // Pré-seleção vinda do Kanban (?board=ID&column=ID)
        if ($board = request()->integer('board')) {
            $this->data['board_id'] = $board;
        }
        if ($column = request()->integer('column')) {
            $this->data['board_column_id'] = $column;
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        if (! TicketResource::canAssignOthers(null)) {
            unset($data['assignee_id']);
        }

        try {
            return app(TicketService::class)->create($data, auth()->user());
        } catch (HelpdeskException $e) {
            Notification::make()->title('Não foi possível abrir o ticket')->body($e->getMessage())->danger()->send();
            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return TicketResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Ticket '.$this->record->number.' aberto';
    }
}
