<?php

namespace App\Filament\Resources\CatalogCandidates\Pages;

use App\Filament\Resources\CatalogCandidates\CatalogCandidateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCatalogCandidates extends ListRecords
{
    protected static string $resource = CatalogCandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
