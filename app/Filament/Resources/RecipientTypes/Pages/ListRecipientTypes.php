<?php

namespace App\Filament\Resources\RecipientTypes\Pages;

use App\Filament\Resources\RecipientTypes\RecipientTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecipientTypes extends ListRecords
{
    protected static string $resource = RecipientTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
