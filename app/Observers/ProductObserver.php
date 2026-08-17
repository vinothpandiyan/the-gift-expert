<?php

namespace App\Observers;

use App\Actions\Product\RecordProductSlugRedirectAction;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Throwable;

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

    public function forceDeleted(Product $product): void
    {
        try {
            Storage::disk((string) config('media.product_images.disk'))
                ->deleteDirectory('products/'.$product->id.'/images');
        } catch (Throwable) {
            // Missing directories must not block product deletion.
        }
    }
}
