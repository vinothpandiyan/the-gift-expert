<?php

namespace Tests\Feature\Discovery;

use App\Enums\AffiliateLinkStatus;
use App\Models\AffiliateLink;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Merchant;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GiftShowPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_gift_renders_lovable_detail_hierarchy(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Ceramic Mug',
            'slug' => 'ceramic-mug',
            'short_description' => 'A lovely mug for daily coffee.',
            'description' => "A simple gift for coffee drinkers who enjoy a sturdy everyday mug.\nIt feels considered without being fussy.",
            'brand' => 'Kiln & Co',
            'price_amount' => '499.00',
            'price_currency' => 'INR',
        ]);

        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $coffee = $this->interest('Coffee');
        $personalized = GiftType::query()->create([
            'name' => 'Personalized Gifts',
            'slug' => 'personalized-gifts',
            'is_active' => true,
        ]);
        $travel = Category::query()->create([
            'name' => 'Travel Accessories',
            'slug' => 'travel-accessories',
            'is_active' => true,
        ]);

        $product->relationships()->attach($husband);
        $product->occasions()->attach($birthday);
        $product->interests()->attach($coffee);
        $product->giftTypes()->attach($personalized);
        $product->categories()->attach($travel->id, ['is_primary' => true]);

        $response = $this->get(DiscoveryUrl::gift($product->slug));
        $link = $product->affiliateLinks->first();

        $response
            ->assertOk()
            ->assertSee('Ceramic Mug', false)
            ->assertSee('A lovely mug for daily coffee.', false)
            ->assertSee('Around ₹499', false)
            ->assertSee('at Example Merchant', false)
            ->assertSee('Check price at Example Merchant', false)
            ->assertSee(DiscoveryUrl::affiliateOut($link->uuid), false)
            ->assertDontSee('href="'.$link->url.'"', false)
            ->assertSee('Price and availability may change on the merchant website.', false)
            ->assertSee("Why it's a great gift", false)
            ->assertSee('A simple gift for coffee drinkers', false)
            ->assertSee('It feels considered without being fussy.', false)
            ->assertSee('Best for', false)
            ->assertSee('Recipients', false)
            ->assertSee('Occasions', false)
            ->assertSee('Interests', false)
            ->assertSee('Gift types', false)
            ->assertSee('Husband', false)
            ->assertSee('Birthday', false)
            ->assertSee('Coffee', false)
            ->assertSee('Personalized Gifts', false)
            ->assertSee(DiscoveryUrl::relationship('husband'), false)
            ->assertSee(DiscoveryUrl::occasion('birthday'), false)
            ->assertSee(DiscoveryUrl::interest('coffee'), false)
            ->assertSee(DiscoveryUrl::giftType('personalized-gifts'), false)
            ->assertSee('Gift details', false)
            ->assertSee('Brand', false)
            ->assertSee('Kiln &amp; Co', false)
            ->assertSee('Travel Accessories', false)
            ->assertSee('Where to buy', false)
            ->assertSee('View deal', false)
            ->assertDontSee('add to cart', false)
            ->assertDontSee('Save this idea', false)
            ->assertDontSee('Great Match', false)
            ->assertDontSee('Why we picked it', false)
            ->assertDontSee('View on Example Merchant', false);
    }

    public function test_gift_without_image_renders_placeholder(): void
    {
        $merchant = $this->merchant();

        $product = Product::factory()->published()->create([
            'slug' => 'no-image-gift',
            'name' => 'No Image Gift',
        ]);

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://example.com/no-image-gift',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee('Image coming soon', false)
            ->assertDontSee('m.media-amazon.com', false);
    }

    public function test_gallery_renders_multiple_images_and_caps_at_five(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'gallery-gift',
            'name' => 'Gallery Gift',
        ]);

        foreach (range(2, 6) as $index) {
            ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => 'images/gallery-'.$index.'.webp',
                'is_primary' => false,
                'sort_order' => $index,
            ]);
        }

        $html = $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee('View image 1', false)
            ->assertSee('View image 5', false)
            ->getContent();

        $this->assertStringNotContainsString('View image 6', $html);
        $this->assertStringNotContainsString('images/gallery-6.webp', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('fetchpriority="high"', $html);
    }

    public function test_affiliate_cta_uses_outbound_route(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'affiliate-cta-gift',
            'name' => 'Affiliate CTA Gift',
        ]);

        $link = $product->affiliateLinks->first();

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee(DiscoveryUrl::affiliateOut($link->uuid), false)
            ->assertDontSee($link->url, false);
    }

    public function test_inactive_affiliate_link_is_excluded_and_missing_link_renders_unavailable_state(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'unavailable-gift',
            'name' => 'Unavailable Gift',
        ]);
        $link = $product->affiliateLinks->first();
        $link->update(['status' => AffiliateLinkStatus::Inactive]);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee("We're currently checking where this gift is available.", false)
            ->assertDontSee('Check price at', false)
            ->assertDontSee('Where to buy', false)
            ->assertDontSee(DiscoveryUrl::affiliateOut($link->uuid), false);
    }

    public function test_multiple_merchants_render_primary_cta_and_where_to_buy_rows(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'multi-merchant-gift',
            'name' => 'Multi Merchant Gift',
        ]);
        $amazon = $product->affiliateLinks->first();
        $amazon->update(['external_product_id' => 'AMZ-1']);

        $flipkart = Merchant::query()->create([
            'name' => 'Flipkart',
            'slug' => 'flipkart',
            'affiliate_network' => 'flipkart',
            'is_active' => true,
        ]);

        $flipkartLink = AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $flipkart->id,
            'url' => 'https://www.flipkart.com/multi-merchant-gift',
            'external_product_id' => 'FK-1',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => false,
        ]);

        $html = $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee('Check price at Example Merchant', false)
            ->assertSee('Flipkart', false)
            ->assertSee(DiscoveryUrl::affiliateOut($amazon->uuid), false)
            ->assertSee(DiscoveryUrl::affiliateOut($flipkartLink->uuid), false)
            ->getContent();

        $this->assertStringNotContainsString('https://www.flipkart.com/multi-merchant-gift', $html);
        $this->assertStringNotContainsString($amazon->url, $html);
        $this->assertSame(2, substr_count($html, 'View deal'));
    }

    public function test_duplicate_merchant_offers_collapse_to_one_row(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'duplicate-merchant-gift',
            'name' => 'Duplicate Merchant Gift',
        ]);
        $primary = $product->affiliateLinks->first();
        $primary->update(['external_product_id' => 'AMZ-PRIMARY']);

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $primary->merchant_id,
            'url' => 'https://example.com/duplicate-alt',
            'external_product_id' => 'AMZ-ALT',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => false,
        ]);

        $html = $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'View deal'));
        $this->assertStringNotContainsString('https://example.com/duplicate-alt', $html);
    }

    public function test_null_and_zero_prices_are_omitted(): void
    {
        $unpriced = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'unpriced-gift',
            'name' => 'Unpriced Gift',
            'price_amount' => null,
        ]);

        $this->get(DiscoveryUrl::gift($unpriced->slug))
            ->assertOk()
            ->assertDontSee('Around', false)
            ->assertDontSee('₹0', false);

        $zero = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'zero-price-gift',
            'name' => 'Zero Price Gift',
            'price_amount' => '0.00',
        ]);

        $this->get(DiscoveryUrl::gift($zero->slug))
            ->assertOk()
            ->assertDontSee('Around ₹0', false);
    }

    public function test_missing_description_omits_why_section(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'no-why-gift',
            'name' => 'No Why Gift',
            'description' => null,
        ]);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertDontSee("Why it's a great gift", false);
    }

    public function test_related_gifts_use_shared_category_and_exclude_current_product(): void
    {
        $category = Category::query()->create([
            'name' => 'Desk Accessories',
            'slug' => 'desk-accessories',
            'is_active' => true,
        ]);

        $current = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Current Organiser',
            'slug' => 'current-organiser',
        ]);
        $related = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Related Notebook',
            'slug' => 'related-notebook',
        ]);
        $elsewhere = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Unrelated Lamp',
            'slug' => 'unrelated-lamp',
        ]);

        $otherCategory = Category::query()->create([
            'name' => 'Lighting',
            'slug' => 'lighting',
            'is_active' => true,
        ]);

        $current->categories()->attach($category->id, ['is_primary' => true]);
        $related->categories()->attach($category->id, ['is_primary' => true]);
        $elsewhere->categories()->attach($otherCategory->id, ['is_primary' => true]);

        $this->get(DiscoveryUrl::gift($current->slug))
            ->assertOk()
            ->assertSee('You may also like', false)
            ->assertSee('Related Notebook', false)
            ->assertSee(DiscoveryUrl::gift($related->slug), false)
            ->assertDontSee('Unrelated Lamp', false);
    }

    public function test_related_section_is_omitted_when_product_has_no_taxonomy(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Lonely Gift',
            'slug' => 'lonely-gift',
        ]);

        GiftCatalogTestHelpers::publishedGift([
            'name' => 'Some Other Gift',
            'slug' => 'some-other-gift',
        ]);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertDontSee('You may also like', false)
            ->assertDontSee('Some Other Gift', false);
    }

    public function test_draft_gift_remains_not_found(): void
    {
        Product::factory()->draft()->create(['slug' => 'draft-gift-page']);

        $this->get(DiscoveryUrl::gift('draft-gift-page'))->assertNotFound();
    }

    public function test_context_does_not_alter_canonical(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'canonical-context-gift',
            'name' => 'Canonical Context Gift',
        ]);
        $husband = $this->relationship('Husband');
        $product->relationships()->attach($husband);

        $canonical = DiscoveryUrl::gift($product->slug, absolute: true);

        $this->get(DiscoveryUrl::gift($product->slug, context: 'relationship:husband'))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
            ->assertDontSee('<link rel="canonical" href="'.$canonical.'?context=', false);
    }

    public function test_sparse_taxonomy_omits_empty_best_for_and_detail_groups(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'sparse-gift',
            'name' => 'Sparse Gift',
            'brand' => null,
        ]);
        $kids = RecipientType::query()->create([
            'name' => 'Kids',
            'slug' => 'kids',
            'is_active' => true,
        ]);
        $teacher = Profession::query()->create([
            'name' => 'Teacher',
            'slug' => 'teacher',
            'is_active' => true,
        ]);
        $product->recipientTypes()->attach($kids);
        $product->professions()->attach($teacher);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertDontSee('Best for', false)
            ->assertSee('Gift details', false)
            ->assertSee('Kids', false)
            ->assertSee('Teacher', false)
            ->assertSee('Gifts for Kids', false);
    }

    public function test_detail_query_count_does_not_grow_with_more_related_gifts(): void
    {
        $category = Category::query()->create([
            'name' => 'Query Bound Category',
            'slug' => 'query-bound-category',
            'is_active' => true,
        ]);
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $travel = $this->interest('Travel');

        $product = $this->fullyTaggedGift('query-bound-gift', $category, $husband, $birthday, $travel);

        foreach (range(1, 2) as $index) {
            $related = GiftCatalogTestHelpers::publishedGift([
                'name' => "Related Bound {$index}",
                'slug' => "related-bound-{$index}",
            ]);
            $related->categories()->attach($category->id, ['is_primary' => true]);
        }

        $this->get(DiscoveryUrl::gift($product->slug))->assertOk();

        $first = $this->countQueries(fn () => $this->get(DiscoveryUrl::gift($product->slug))->assertOk());

        foreach (range(3, 6) as $index) {
            $related = GiftCatalogTestHelpers::publishedGift([
                'name' => "Related Bound {$index}",
                'slug' => "related-bound-{$index}",
            ]);
            $related->categories()->attach($category->id, ['is_primary' => true]);
        }

        $second = $this->countQueries(fn () => $this->get(DiscoveryUrl::gift($product->slug))->assertOk());

        $this->assertSame($first, $second);
        $this->assertLessThanOrEqual(40, $first);
    }

    public function test_breadcrumb_and_term_present(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'breadcrumb-gift',
            'name' => 'Breadcrumb Gift',
        ]);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee('Breadcrumb Gift', false)
            ->assertSee('Gift Ideas', false);
    }

    public function test_long_gift_name_and_description_remain_visible(): void
    {
        $name = 'An unusually long gift name that should still be readable on the gift detail page without breaking the layout chrome';
        $product = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'long-copy-gift',
            'name' => $name,
            'short_description' => str_repeat('A thoughtful short description that keeps going. ', 8),
        ]);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee($name, false)
            ->assertSee('A thoughtful short description that keeps going.', false)
            ->assertSee('Check price at', false);
    }

    private function fullyTaggedGift(
        string $slug,
        Category $category,
        Relationship $relationship,
        Occasion $occasion,
        Interest $interest,
    ): Product {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Query Bound Gift',
            'slug' => $slug,
            'short_description' => 'A tagged gift for query bounds.',
            'description' => "First reason.\nSecond reason.",
            'brand' => 'Bound Brand',
            'price_amount' => '1499.00',
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'images/'.$slug.'-2.webp',
            'is_primary' => false,
            'sort_order' => 1,
        ]);

        $flipkart = Merchant::query()->create([
            'name' => 'Flipkart',
            'slug' => 'flipkart-bound',
            'affiliate_network' => 'flipkart',
            'is_active' => true,
        ]);

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $flipkart->id,
            'url' => 'https://www.flipkart.com/'.$slug,
            'external_product_id' => 'FK-BOUND',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => false,
        ]);

        $product->categories()->attach($category->id, ['is_primary' => true]);
        $product->relationships()->attach($relationship);
        $product->occasions()->attach($occasion);
        $product->interests()->attach($interest);

        return $product;
    }

    private function relationship(string $name): Relationship
    {
        return Relationship::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function occasion(string $name): Occasion
    {
        return Occasion::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function interest(string $name): Interest
    {
        return Interest::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function merchant(): Merchant
    {
        return Merchant::query()->firstOrCreate(
            ['slug' => 'example-merchant'],
            [
                'name' => 'Example Merchant',
                'affiliate_network' => 'example',
                'is_active' => true,
            ],
        );
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();

        return count(DB::getQueryLog());
    }
}
