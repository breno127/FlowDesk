<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + RoleResource::fillPermissions($this->record->load('permissions'));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (RoleResource::isSuperAdmin($record)) {
            return $record; // super-admin é imutável pelo painel
        }

        return DB::transaction(function () use ($record, $data) {
            $record->update(['name' => $data['name']]);
            $record->syncPermissions(RoleResource::extractPermissions($data));

            return $record;
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => ! RoleResource::isSuperAdmin($this->record) && ! $this->record->users()->exists()),
        ];
    }
}
