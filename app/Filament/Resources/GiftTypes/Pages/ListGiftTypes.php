<?php

namespace App\Filament\Resources\GiftTypes\Pages;

use App\Filament\Resources\GiftTypes\GiftTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGiftTypes extends ListRecords
{
    protected static string $resource = GiftTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
