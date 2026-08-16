<?php

namespace App\Filament\Resources\SeoLandingPages\Pages;

use App\Filament\Resources\SeoLandingPages\SeoLandingPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSeoLandingPages extends ListRecords
{
    protected static string $resource = SeoLandingPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
