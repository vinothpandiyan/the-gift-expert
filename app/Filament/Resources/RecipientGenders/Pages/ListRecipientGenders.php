<?php

namespace App\Filament\Resources\RecipientGenders\Pages;

use App\Filament\Resources\RecipientGenders\RecipientGenderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecipientGenders extends ListRecords
{
    protected static string $resource = RecipientGenderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
