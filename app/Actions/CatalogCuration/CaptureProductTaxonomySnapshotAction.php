<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\ProductTaxonomySnapshot;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class CaptureProductTaxonomySnapshotAction
{
    public function execute(Product $product): ProductTaxonomySnapshot
    {
        $product->loadMissing([
            'categories',
            'relationships',
            'occasions',
            'interests',
            'giftTypes',
            'recipientTypes',
            'recipientGenders',
            'professions',
        ]);

        $primary = $product->categories->firstWhere('pivot.is_primary', true)
            ?? $product->categories->first();

        return new ProductTaxonomySnapshot(
            primaryCategoryId: $primary?->id !== null ? (int) $primary->id : null,
            categoryIds: $this->ids($product->categories),
            relationshipIds: $this->ids($product->relationships),
            occasionIds: $this->ids($product->occasions),
            interestIds: $this->ids($product->interests),
            giftTypeIds: $this->ids($product->giftTypes),
            recipientTypeIds: $this->ids($product->recipientTypes),
            recipientGenderIds: $this->ids($product->recipientGenders),
            professionIds: $this->ids($product->professions),
            classificationStatus: $product->taxonomy_classification_status?->value,
            names: [
                'categories' => $this->names($product->categories),
                'relationships' => $this->names($product->relationships),
                'occasions' => $this->names($product->occasions),
                'interests' => $this->names($product->interests),
                'gift_types' => $this->names($product->giftTypes),
                'recipient_types' => $this->names($product->recipientTypes),
                'recipient_genders' => $this->names($product->recipientGenders),
                'professions' => $this->names($product->professions),
            ],
        );
    }

    /**
     * @param  Collection<int, Model>  $records
     * @return list<int>
     */
    private function ids(Collection $records): array
    {
        return $records
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Model>  $records
     * @return array<int, string>
     */
    private function names(Collection $records): array
    {
        return $records
            ->mapWithKeys(fn (Model $record): array => [(int) $record->getKey() => (string) $record->getAttribute('name')])
            ->all();
    }
}
