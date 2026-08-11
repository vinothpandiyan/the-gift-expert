<?php

namespace App\Actions\Product;

use App\Models\ProductSlugRedirect;
use Illuminate\Support\Facades\DB;

class RecordProductSlugRedirectAction
{
    public function execute(string $fromSlug, string $toSlug, ?int $productId = null): void
    {
        if ($fromSlug === $toSlug) {
            return;
        }

        DB::transaction(function () use ($fromSlug, $toSlug, $productId): void {
            ProductSlugRedirect::query()
                ->where('to_slug', $fromSlug)
                ->update(['to_slug' => $toSlug]);

            ProductSlugRedirect::query()->updateOrCreate(
                ['from_slug' => $fromSlug],
                [
                    'to_slug' => $toSlug,
                    'product_id' => $productId,
                ],
            );
        });
    }
}
