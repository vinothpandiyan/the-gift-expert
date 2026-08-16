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

        $this->seedPublishedGift(
            slug: 'personalized-wooden-photo-frame',
            name: 'Personalized Wooden Photo Frame',
            shortDescription: 'A customizable wooden photo frame gift.',
            description: 'Sample published gift with taxonomy links for local development.',
            price: '1499.00',
            categorySlug: 'personalized-gifts',
            occasionSlugs: ['birthday'],
            relationshipSlugs: ['husband'],
            interestSlugs: ['photography'],
            giftTypeSlugs: ['return-gifts'],
        );

        $this->seedPublishedGift(
            slug: 'classic-leather-wallet',
            name: 'Classic Leather Wallet',
            shortDescription: 'A slim leather wallet suitable for everyday carry.',
            description: 'Sample published gift for partner and father birthday or anniversary browsing.',
            price: '1899.00',
            categorySlug: 'fashion-and-accessories',
            occasionSlugs: ['birthday', 'anniversary'],
            relationshipSlugs: ['husband', 'boyfriend', 'father'],
        );

        $this->seedPublishedGift(
            slug: 'pour-over-coffee-kit',
            name: 'Pour-Over Coffee Kit',
            shortDescription: 'A simple pour-over set for home coffee brewing.',
            description: 'Sample published gift for coffee-led partner and father birthday browsing.',
            price: '1299.00',
            categorySlug: 'food-and-beverages',
            occasionSlugs: ['birthday'],
            relationshipSlugs: ['husband', 'boyfriend', 'father'],
            interestSlugs: ['coffee'],
        );

        $this->seedPublishedGift(
            slug: 'stainless-steel-travel-tumbler',
            name: 'Stainless Steel Travel Tumbler',
            shortDescription: 'An insulated tumbler for commuting coffee.',
            description: 'Sample published gift for coffee drinkers and office farewells.',
            price: '899.00',
            categorySlug: 'food-and-beverages',
            occasionSlugs: ['birthday', 'farewell'],
            relationshipSlugs: ['husband', 'boyfriend', 'colleagues'],
            interestSlugs: ['coffee'],
        );

        $this->seedPublishedGift(
            slug: 'wireless-noise-cancelling-earbuds',
            name: 'Wireless Noise-Cancelling Earbuds',
            shortDescription: 'Compact wireless earbuds for commuting and focus.',
            description: 'Sample published gift for technology-led partner and family browsing.',
            price: '4999.00',
            categorySlug: 'electronics',
            occasionSlugs: ['birthday', 'anniversary'],
            relationshipSlugs: ['husband', 'boyfriend', 'brother', 'son'],
            interestSlugs: ['technology'],
        );

        $this->seedPublishedGift(
            slug: 'compact-power-bank',
            name: 'Compact Power Bank',
            shortDescription: 'A pocket power bank for phones and earbuds.',
            description: 'Sample published gift for technology-led birthday browsing.',
            price: '1599.00',
            categorySlug: 'electronics',
            occasionSlugs: ['birthday'],
            relationshipSlugs: ['husband', 'boyfriend', 'brother'],
            interestSlugs: ['technology'],
        );

        $this->seedPublishedGift(
            slug: 'floral-eau-de-parfum',
            name: 'Floral Eau de Parfum',
            shortDescription: 'A wearable floral fragrance in a gift-ready bottle.',
            description: 'Sample published gift for partner and family birthday or anniversary browsing.',
            price: '2499.00',
            categorySlug: 'beauty-and-grooming',
            occasionSlugs: ['birthday', 'anniversary'],
            relationshipSlugs: ['wife', 'girlfriend', 'mother', 'sister'],
        );

        $this->seedPublishedGift(
            slug: 'gold-pendant-necklace',
            name: 'Gold Pendant Necklace',
            shortDescription: 'A simple pendant necklace for everyday wear.',
            description: 'Sample published gift for partner and mother birthday or anniversary browsing.',
            price: '3299.00',
            categorySlug: 'fashion-and-accessories',
            occasionSlugs: ['birthday', 'anniversary'],
            relationshipSlugs: ['wife', 'girlfriend', 'mother'],
        );

        $this->seedPublishedGift(
            slug: 'beginner-yoga-mat',
            name: 'Beginner Yoga Mat',
            shortDescription: 'A cushioned yoga mat for home practice.',
            description: 'Sample published gift for fitness-led partner birthday browsing.',
            price: '1199.00',
            categorySlug: 'wellness',
            occasionSlugs: ['birthday'],
            relationshipSlugs: ['wife', 'girlfriend'],
            interestSlugs: ['fitness'],
        );

        $this->seedPublishedGift(
            slug: 'gourmet-snack-hamper',
            name: 'Gourmet Snack Hamper',
            shortDescription: 'A mixed snack hamper for sharing at home.',
            description: 'Sample published gift for food-led family birthday, housewarming, and Diwali browsing.',
            price: '1799.00',
            categorySlug: 'food-and-beverages',
            occasionSlugs: ['birthday', 'housewarming', 'diwali'],
            relationshipSlugs: ['wife', 'mother', 'parents'],
            interestSlugs: ['food'],
        );
    }

    /**
     * @param  list<string>  $occasionSlugs
     * @param  list<string>  $relationshipSlugs
     * @param  list<string>  $interestSlugs
     * @param  list<string>  $giftTypeSlugs
     */
    private function seedPublishedGift(
        string $slug,
        string $name,
        string $shortDescription,
        string $description,
        string $price,
        string $categorySlug,
        array $occasionSlugs,
        array $relationshipSlugs,
        array $interestSlugs = [],
        array $giftTypeSlugs = [],
    ): void {
        $product = Product::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'short_description' => $shortDescription,
                'description' => $description,
                'status' => ProductStatus::Draft,
                'price_amount' => $price,
                'price_currency' => 'INR',
            ],
        );

        ProductImage::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'path' => 'seed/'.$slug.'.jpg',
            ],
            [
                'disk' => 'public',
                'alt_text' => $name,
                'sort_order' => 1,
                'is_primary' => true,
            ],
        );

        $merchant = Merchant::query()->where('slug', 'placeholder')->firstOrFail();

        AffiliateLink::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'merchant_id' => $merchant->id,
            ],
            [
                'url' => 'https://example.com/'.$slug,
                'external_product_id' => 'DEV-'.strtoupper(str_replace('-', '_', $slug)),
                'is_primary' => true,
                'status' => AffiliateLinkStatus::Active,
            ],
        );

        $primaryCategory = Category::query()->where('slug', $categorySlug)->whereNull('parent_id')->first();

        if ($primaryCategory !== null) {
            $product->categories()->syncWithoutDetaching([
                $primaryCategory->id => ['is_primary' => true],
            ]);
        }

        $this->syncBySlug($product, 'occasions', Occasion::class, $occasionSlugs);
        $this->syncBySlug($product, 'relationships', Relationship::class, $relationshipSlugs);
        $this->syncBySlug($product, 'interests', Interest::class, $interestSlugs);
        $this->syncBySlug($product, 'giftTypes', GiftType::class, $giftTypeSlugs);

        if ($product->status !== ProductStatus::Published) {
            app(PublishProductAction::class)->execute($product->fresh());
        }
    }

    /**
     * @param  class-string  $model
     * @param  list<string>  $slugs
     */
    private function syncBySlug(Product $product, string $relation, string $model, array $slugs): void
    {
        if ($slugs === []) {
            return;
        }

        $ids = $model::query()->whereIn('slug', $slugs)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $product->{$relation}()->syncWithoutDetaching($ids->all());
    }
}
