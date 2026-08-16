<?php

namespace App\Filament\Resources\SeoLandingPages\Pages;

use App\Enums\SeoLandingPageStatus;
use App\Filament\Resources\SeoLandingPages\SeoLandingPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSeoLandingPage extends CreateRecord
{
    protected static string $resource = SeoLandingPageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = SeoLandingPageStatus::Draft->value;

        return $data;
    }
}
