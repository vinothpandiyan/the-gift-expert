<?php

namespace App\Actions\CatalogCuration;

use App\Models\Product;
use App\Models\ProductCurationDecision;

class ResolveEffectiveProductCurationDecisionAction
{
    public function execute(Product $product): ?ProductCurationDecision
    {
        if ($product->relationLoaded('currentCurationDecision')) {
            $current = $product->currentCurationDecision;

            return $current instanceof ProductCurationDecision ? $current : null;
        }

        return ProductCurationDecision::query()
            ->current()
            ->where('product_id', $product->id)
            ->first();
    }

    public function isHumanReviewed(Product $product): bool
    {
        $decision = $this->execute($product);

        return $decision instanceof ProductCurationDecision && $decision->isHumanReviewed();
    }

    public function isUnresolved(Product $product): bool
    {
        $decision = $this->execute($product);

        return ! $decision instanceof ProductCurationDecision || ! $decision->isHumanReviewed();
    }
}
