<?php

namespace App\Filament\Resources\NavigationMenus\Pages;

use App\Filament\Resources\NavigationMenus\NavigationMenuResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNavigationMenu extends CreateRecord
{
    protected static string $resource = NavigationMenuResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return NavigationMenuResource::mutateMenuData($data);
    }
}
