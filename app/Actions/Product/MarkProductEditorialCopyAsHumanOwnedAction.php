<?php

namespace App\Actions\Product;

use App\Enums\EditorialOwnership;
use App\Models\Product;
use App\Models\User;

class MarkProductEditorialCopyAsHumanOwnedAction
{
    public function execute(Product $product, ?User $user = null): Product
    {
        $product->editorial_ownership = EditorialOwnership::Human;
        $product->editorial_generation_version = null;
        $product->editorial_reviewed_at = now();
        $product->editorial_reviewed_by_user_id = $user?->id;
        $product->save();

        return $product->fresh() ?? $product;
    }
}
