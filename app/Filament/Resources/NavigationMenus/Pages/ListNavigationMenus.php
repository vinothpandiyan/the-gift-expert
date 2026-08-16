<?php

namespace App\Filament\Resources\NavigationMenus\Pages;

use App\Filament\Resources\NavigationMenus\NavigationMenuResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNavigationMenus extends ListRecords
{
    protected static string $resource = NavigationMenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
