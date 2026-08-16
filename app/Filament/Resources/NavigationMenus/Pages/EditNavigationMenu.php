<?php

namespace App\Filament\Resources\NavigationMenus\Pages;

use App\Filament\Resources\NavigationMenus\NavigationMenuResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNavigationMenu extends EditRecord
{
    protected static string $resource = NavigationMenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return NavigationMenuResource::mutateMenuData($data);
    }
}
