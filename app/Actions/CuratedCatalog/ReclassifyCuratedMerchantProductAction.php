<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\ClassifyCuratedMerchantProductResult;
use App\Models\Product;

class ReclassifyCuratedMerchantProductAction
{
    public function __construct(
        private ClassifyCuratedMerchantProductAction $classify,
    ) {}

    public function execute(Product $product): ClassifyCuratedMerchantProductResult
    {
        $product = $product->fresh() ?? $product;

        if ($product->taxonomyClassificationIsHumanLocked()) {
            return $this->classify->execute(
                $product,
                force: true,
                retryFailed: false,
                preserveHumanAppliedTaxonomy: true,
            );
        }

        return $this->classify->execute(
            $product,
            force: true,
            retryFailed: true,
        );
    }
}
