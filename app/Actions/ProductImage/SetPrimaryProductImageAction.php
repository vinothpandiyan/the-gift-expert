<?php

namespace App\Actions\ProductImage;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;

class SetPrimaryProductImageAction
{
    public function execute(ProductImage $image): void
    {
        DB::transaction(function () use ($image): void {
            ProductImage::query()
                ->where('product_id', $image->product_id)
                ->whereKeyNot($image->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            if (! $image->is_primary) {
                $image->is_primary = true;
                $image->save();
            }
        });
    }

    public function ensureForProduct(Product $product): void
    {
        if ($product->images()->where('is_primary', true)->exists()) {
            return;
        }

        $fallback = $product->images()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($fallback instanceof ProductImage) {
            $this->execute($fallback);
        }
    }
}
