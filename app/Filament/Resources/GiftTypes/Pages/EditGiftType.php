<?php

namespace App\Filament\Resources\GiftTypes\Pages;

use App\Filament\Resources\GiftTypes\GiftTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditGiftType extends EditRecord
{
    protected static string $resource = GiftTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
