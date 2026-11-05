<?php

namespace App\Filament\Resources\BoardResource\Pages;

use App\Exceptions\Helpdesk\HelpdeskException;
use App\Filament\Resources\BoardResource;
use App\Models\Board;
use App\Services\Kanban\BoardService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateBoard extends CreateRecord
{
    protected static string $resource = BoardResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return DB::transaction(function () use ($data) {
                $board = Board::query()->create(Arr::except($data, ['columns']));

                return app(BoardService::class)->syncColumns($board, $data['columns'] ?? []);
            });
        } catch (HelpdeskException $e) {
            Notification::make()->title('Quadro não criado')->body($e->getMessage())->danger()->send();
            $this->halt();
        }
    }
}
