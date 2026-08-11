<?php

namespace App\Filament\Resources\BudgetRanges\Pages;

use App\Filament\Resources\BudgetRanges\BudgetRangeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBudgetRanges extends ListRecords
{
    protected static string $resource = BudgetRangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
