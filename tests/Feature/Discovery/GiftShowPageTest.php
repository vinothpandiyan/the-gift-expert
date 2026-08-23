<?php

namespace Tests\Feature\Discovery;

use App\Enums\AffiliateLinkStatus;
use App\Models\AffiliateLink;
use App\Models\Interest;
use App\Models\Merchant;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Relationship;
use App\Support\DiscoveryUrl;
use App\Support\Terminology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GiftShowPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_gift_renders_lean_decision_page_with_taxonomy_badges(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Ceramic Mug',
            'slug' => 'ceramic-mug',
            'short_description' => 'A lovely mug for daily coffee.',
            'description' => 'A simple gift for coffee drinkers who enjoy a sturdy everyday mug.',
            'price_amount' => '499.00',
        ]);

        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);
        $birthday = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);
        $coffee = Interest::query()->create([
            'name' => 'Coffee',
            'slug' => 'coffee',
            'is_active' => true,
        ]);

        $product->relationships()->attach($husband);
        $product->occasions()->attach($birthday);
        $product->interests()->attach($coffee);

        $response = $this->get(DiscoveryUrl::gift($product->slug));

        $response
            ->assertOk()
            ->assertSee('Ceramic Mug', false)
            ->assertSee('A lovely mug for daily coffee.', false)
            ->assertSee('Why we picked it', false)
            ->assertSee('A simple gift for coffee drinkers', false)
            ->assertSee('Best for', false)
            ->assertSee('Good for', false)
            ->assertSee('Interests', false)
            ->assertSee('Husband', false)
            ->assertSee('Birthday', false)
            ->assertSee('Coffee', false)
            ->assertSee('View on Example Merchant', false)
            ->assertSee('affiliate', false)
            ->assertSee(DiscoveryUrl::relationship('husband'), false)
            ->assertSee(DiscoveryUrl::occasion('birthday'), false)
            ->assertSee(DiscoveryUrl::interest('coffee'), false)
            ->assertDontSee('Recipients:', false)
            ->assertDontSee('Professions:', false)
            ->assertDontSee('Gift types:', false)
            ->assertDontSee('add to cart', false)
            ->assertDontSee('quantity', false);
    }

    public function test_gift_without_image_renders_placeholder(): void
    {
        $merchant = Merchant::query()->firstOrCreate(
            ['slug' => 'example-merchant'],
            [
                'name' => 'Example Merchant',
                'affiliate_network' => 'example',
                'is_active' => true,
            ],
        );

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

    public function test_affiliate_cta_uses_outbound_route(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'affiliate-cta-gift',
            'name' => 'Affiliate CTA Gift',
        ]);

        $link = $product->affiliateLinks->first();

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee(DiscoveryUrl::affiliateOut($link->uuid), false);
    }

    public function test_draft_gift_remains_not_found(): void
    {
        Product::factory()->draft()->create(['slug' => 'draft-gift-page']);

        $this->get(DiscoveryUrl::gift('draft-gift-page'))->assertNotFound();
    }

    public function test_breadcrumb_and_term_present(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'slug' => 'breadcrumb-gift',
            'name' => 'Breadcrumb Gift',
        ]);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee(Terminology::gift(), false);
    }
}
