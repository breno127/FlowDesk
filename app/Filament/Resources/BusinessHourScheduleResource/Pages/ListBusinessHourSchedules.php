<?php

namespace App\Filament\Resources\BusinessHourScheduleResource\Pages;

use App\Filament\Resources\BusinessHourScheduleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBusinessHourSchedules extends ListRecords
{
    protected static string $resource = BusinessHourScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
