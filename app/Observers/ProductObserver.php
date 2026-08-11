<?php

namespace App\Observers;

use App\Actions\Product\RecordProductSlugRedirectAction;
use App\Models\Product;

class ProductObserver
{
    public function updated(Product $product): void
    {
        if (! $product->wasChanged('slug')) {
            return;
        }

        app(RecordProductSlugRedirectAction::class)->execute(
            fromSlug: (string) $product->getOriginal('slug'),
            toSlug: $product->slug,
            productId: $product->id,
        );
    }
}
