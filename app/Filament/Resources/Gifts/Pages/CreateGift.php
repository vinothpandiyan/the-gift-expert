<?php

namespace App\Filament\Resources\Gifts\Pages;

use App\Enums\EditorialOwnership;
use App\Enums\ProductStatus;
use App\Filament\Resources\Gifts\Concerns\AppliesGiftTaxonomyFormState;
use App\Filament\Resources\Gifts\GiftResource;
use App\Filament\Resources\Gifts\Schemas\GiftClassificationSchema;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateGift extends CreateRecord
{
    use AppliesGiftTaxonomyFormState;

    protected static string $resource = GiftResource::class;

    /**
     * @var array<string, mixed>
     */
    private array $pendingTaxonomy = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = ProductStatus::Draft->value;
        $data['editorial_ownership'] = EditorialOwnership::Human->value;
        $data['editorial_reviewed_at'] = now();
        $data['editorial_reviewed_by_user_id'] = auth()->id();
        $this->pendingTaxonomy = Arr::only($data, GiftClassificationSchema::formKeys());

        return $this->forgetTaxonomyFormData($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            /** @var Product $product */
            $product = parent::handleRecordCreation($data);

            return $this->persistTaxonomyFormData($product, $this->pendingTaxonomy);
        });
    }
}
