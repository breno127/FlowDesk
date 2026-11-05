<?php

namespace App\Filament\Resources\ClientCompanyResource\Pages;

use App\Filament\Resources\ClientCompanyResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageClientCompanies extends ManageRecords
{
    protected static string $resource = ClientCompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
