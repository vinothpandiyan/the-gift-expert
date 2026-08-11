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
}
