<?php

namespace Database\Seeders;

use App\Actions\Product\PublishProductAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Merchant;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Relationship;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        Product::query()->updateOrCreate(
            ['slug' => 'draft-gift-idea'],
            [
                'name' => 'Draft Gift Idea',
                'short_description' => 'Sample draft gift for local development.',
                'description' => 'This gift remains in draft and is useful for admin workflow testing.',
                'status' => ProductStatus::Draft,
                'price_amount' => '799.00',
                'price_currency' => 'INR',
            ],
        );

        $publishedProduct = Product::query()->updateOrCreate(
            ['slug' => 'personalized-wooden-photo-frame'],
            [
                'name' => 'Personalized Wooden Photo Frame',
                'short_description' => 'A customizable wooden photo frame gift.',
                'description' => 'Sample published gift with taxonomy links for local development.',
                'status' => ProductStatus::Draft,
                'price_amount' => '1499.00',
                'price_currency' => 'INR',
            ],
        );

        ProductImage::query()->updateOrCreate(
            [
                'product_id' => $publishedProduct->id,
                'path' => 'seed/personalized-wooden-photo-frame.jpg',
            ],
            [
                'disk' => 'public',
                'alt_text' => 'Personalized wooden photo frame',
                'sort_order' => 1,
                'is_primary' => true,
            ],
        );

        $merchant = Merchant::query()->where('slug', 'placeholder')->firstOrFail();

        AffiliateLink::query()->updateOrCreate(
            [
                'product_id' => $publishedProduct->id,
                'merchant_id' => $merchant->id,
            ],
            [
                'url' => 'https://example.com/personalized-wooden-photo-frame',
                'external_product_id' => 'DEV-FRAME-001',
                'is_primary' => true,
                'status' => AffiliateLinkStatus::Active,
            ],
        );

        $primaryCategory = Category::query()->where('slug', 'personalized-gifts')->whereNull('parent_id')->first();

        if ($primaryCategory !== null) {
            $publishedProduct->categories()->syncWithoutDetaching([
                $primaryCategory->id => ['is_primary' => true],
            ]);
        }

        $occasion = Occasion::query()->where('slug', 'birthday')->first();
        $relationship = Relationship::query()->where('slug', 'husband')->first();
        $interest = Interest::query()->where('slug', 'photography')->first();
        $giftType = GiftType::query()->where('slug', 'return-gifts')->first();

        if ($occasion !== null) {
            $publishedProduct->occasions()->syncWithoutDetaching([$occasion->id]);
        }

        if ($relationship !== null) {
            $publishedProduct->relationships()->syncWithoutDetaching([$relationship->id]);
        }

        if ($interest !== null) {
            $publishedProduct->interests()->syncWithoutDetaching([$interest->id]);
        }

        if ($giftType !== null) {
            $publishedProduct->giftTypes()->syncWithoutDetaching([$giftType->id]);
        }

        if ($publishedProduct->status !== ProductStatus::Published) {
            app(PublishProductAction::class)->execute($publishedProduct->fresh());
        }
    }
}
