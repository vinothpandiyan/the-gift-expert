<?php

namespace App\Actions\Product;

use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

class PublishProductAction
{
    /**
     * @return array{warnings: list<string>}
     */
    public function execute(Product $product): array
    {
        $errors = [];
        $warnings = [];

        if (config('gift_publication.requirements.name') && blank($product->name)) {
            $errors[] = 'A gift name is required before publishing.';
        }

        if (config('gift_publication.requirements.slug') && blank($product->slug)) {
            $errors[] = 'A gift slug is required before publishing.';
        }

        if (config('gift_publication.requirements.image') && ! $product->images()->exists()) {
            $errors[] = 'Add at least one gift image before publishing.';
        }

        if (config('gift_publication.requirements.active_affiliate_link') && ! $product->affiliateLinks()
            ->where('status', AffiliateLinkStatus::Active)
            ->exists()) {
            $errors[] = 'Add at least one active affiliate link before publishing.';
        }

        if (config('gift_publication.warnings.price_amount') && $product->price_amount === null) {
            $warnings[] = 'This gift has no price amount set.';
        }

        if (config('gift_publication.warnings.primary_category') && ! $product->categories()
            ->wherePivot('is_primary', true)
            ->exists()) {
            $warnings[] = 'No primary gift category is assigned.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'status' => $errors,
            ]);
        }

        $product->status = ProductStatus::Published;

        if ($product->published_at === null) {
            $product->published_at = now();
        }

        $product->save();

        return [
            'warnings' => $warnings,
        ];
    }
}
