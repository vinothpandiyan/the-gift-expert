<?php

namespace App\Filament\Resources\RecipientGenders\Pages;

use App\Filament\Resources\RecipientGenders\RecipientGenderResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditRecipientGender extends EditRecord
{
    protected static string $resource = RecipientGenderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
