<?php

namespace Tests\Feature\Discovery;

use App\Enums\AffiliateLinkStatus;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;

final class GiftCatalogTestHelpers
{
    public static function publishedGift(array $attributes = []): Product
    {
        $merchant = Merchant::query()->firstOrCreate(
            ['slug' => 'example-merchant'],
            [
                'name' => 'Example Merchant',
                'affiliate_network' => 'example',
                'is_active' => true,
            ],
        );

        $product = Product::factory()->published()->create($attributes);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'images/'.$product->slug.'.jpg',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://example.com/'.$product->slug,
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);

        return $product->fresh(['images', 'affiliateLinks.merchant']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, list<object>|object>  $relations
     */
    public static function taggedGift(array $attributes, array $relations): Product
    {
        $product = self::publishedGift($attributes);

        foreach ($relations as $relation => $models) {
            $ids = collect(is_array($models) ? $models : [$models])
                ->map(fn ($model) => (int) $model->id)
                ->all();

            $product->{$relation}()->attach($ids);
        }

        return $product;
    }
}
