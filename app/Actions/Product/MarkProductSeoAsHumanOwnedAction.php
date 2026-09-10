<?php

namespace App\Actions\Product;

use App\Enums\SeoOwnership;
use App\Models\Product;
use App\Models\User;

class MarkProductSeoAsHumanOwnedAction
{
    public function execute(Product $product, ?User $reviewer = null): Product
    {
        $product->seo_ownership = SeoOwnership::Human;
        $product->seo_generation_version = null;
        $product->seo_reviewed_at = now();
        $product->seo_reviewed_by_user_id = $reviewer?->id;
        $product->save();

        return $product->fresh() ?? $product;
    }
}
