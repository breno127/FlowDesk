<?php

namespace App\Filament\Resources\BoardResource\Pages;

use App\Exceptions\Helpdesk\HelpdeskException;
use App\Filament\Resources\BoardResource;
use App\Models\BoardColumn;
use App\Services\Kanban\BoardService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EditBoard extends EditRecord
{
    protected static string $resource = BoardResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['columns'] = $this->record->columns->map(fn (BoardColumn $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'color' => $c->color,
            'category' => $c->category->value,
            'sla_behavior' => $c->sla_behavior->value,
            'wip_limit' => $c->wip_limit,
            'is_initial' => $c->is_initial,
        ])->values()->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return DB::transaction(function () use ($record, $data) {
                $record->update(Arr::except($data, ['columns']));

                return app(BoardService::class)->syncColumns($record, array_values($data['columns'] ?? []));
            });
        } catch (HelpdeskException $e) {
            Notification::make()->title('Alterações não salvas')->body($e->getMessage())->danger()->send();
            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->visible(fn () => $this->record->tickets()->doesntExist()),
        ];
    }
}
