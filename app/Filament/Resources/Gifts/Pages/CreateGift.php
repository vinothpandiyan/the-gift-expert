<?php

namespace App\Filament\Resources\Gifts\Pages;

use App\Enums\ProductStatus;
use App\Filament\Resources\Gifts\GiftResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGift extends CreateRecord
{
    protected static string $resource = GiftResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = ProductStatus::Draft->value;

        return $data;
    }
}
