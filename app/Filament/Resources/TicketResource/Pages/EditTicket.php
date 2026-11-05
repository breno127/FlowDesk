<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Exceptions\Helpdesk\HelpdeskException;
use App\Filament\Resources\TicketResource;
use App\Services\CustomFields\CustomFieldService;
use App\Services\Tickets\TicketService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class EditTicket extends EditRecord
{
    protected static string $resource = TicketResource::class;

    public function getTitle(): string
    {
        return $this->record->number;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['custom_fields'] = app(CustomFieldService::class)->valuesFor($this->record);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! Gate::allows('assign', $record)) {
            unset($data['assignee_id']);
        }
        if (! Gate::allows('move', $record)) {
            unset($data['board_column_id']);
        }
        unset($data['board_id']);

        try {
            return app(TicketService::class)->update($record, $data, auth()->user());
        } catch (HelpdeskException $e) {
            Notification::make()->title('Alteração não aplicada')->body($e->getMessage())->danger()->send();
            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return TicketResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->using(fn () => app(TicketService::class)->delete($this->record, auth()->user())),
        ];
    }
}
