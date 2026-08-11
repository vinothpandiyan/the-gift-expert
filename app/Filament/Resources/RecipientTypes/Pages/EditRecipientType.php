<?php

namespace App\Filament\Resources\RecipientTypes\Pages;

use App\Filament\Resources\RecipientTypes\RecipientTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditRecipientType extends EditRecord
{
    protected static string $resource = RecipientTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
