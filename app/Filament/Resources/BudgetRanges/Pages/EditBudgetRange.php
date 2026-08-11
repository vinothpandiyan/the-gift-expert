<?php

namespace App\Filament\Resources\BudgetRanges\Pages;

use App\Filament\Resources\BudgetRanges\BudgetRangeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditBudgetRange extends EditRecord
{
    protected static string $resource = BudgetRangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
