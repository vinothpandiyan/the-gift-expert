<?php

namespace App\Actions\ProductImage;

use App\Models\ProductImage;

class DeleteProductImageAction
{
    public function __construct(
        private SetPrimaryProductImageAction $setPrimaryProductImage,
    ) {}

    public function execute(ProductImage $image): void
    {
        $product = $image->product;
        $image->delete();

        if ($product !== null) {
            $this->setPrimaryProductImage->ensureForProduct($product);
        }
    }
}
