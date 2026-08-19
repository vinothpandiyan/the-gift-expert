<?php

namespace App\Actions\Product;

use App\CommercialSourcing\ValidatedProductTaxonomyClassification;
use App\Enums\ProductStatus;
use App\Models\Product;

class ApplyProductTaxonomyClassificationAction
{
    public function execute(Product $product, ValidatedProductTaxonomyClassification $classification): bool
    {
        if ($product->status !== ProductStatus::Draft) {
            return false;
        }

        $categorySync = [];

        foreach ($classification->categoryIds as $categoryId) {
            $categorySync[$categoryId] = [
                'is_primary' => $categoryId === $classification->primaryCategoryId,
            ];
        }

        if (
            $classification->primaryCategoryId !== null
            && ! array_key_exists($classification->primaryCategoryId, $categorySync)
        ) {
            $categorySync[$classification->primaryCategoryId] = ['is_primary' => true];
        }

        $product->categories()->sync($categorySync);
        $product->occasions()->sync($classification->occasionIds);
        $product->relationships()->sync($classification->relationshipIds);
        $product->recipientTypes()->sync($classification->recipientTypeIds);
        $product->interests()->sync($classification->interestIds);
        $product->professions()->sync($classification->professionIds);
        $product->giftTypes()->sync($classification->giftTypeIds);

        return true;
    }
}
