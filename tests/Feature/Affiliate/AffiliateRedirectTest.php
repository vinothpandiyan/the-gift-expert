<?php

namespace Tests\Feature\Affiliate;

use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Models\AffiliateClick;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Discovery\GiftCatalogTestHelpers;
use Tests\TestCase;

class AffiliateRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_affiliate_link_redirects_with_click_recorded(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift(['slug' => 'redirect-gift']);
        $link = $product->affiliateLinks->first();

        $this->assertNotNull($link);
        $this->assertNotNull($link->uuid);

        $response = $this->get(DiscoveryUrl::affiliateOut($link->uuid));

        $response->assertRedirect($link->url);
        $this->assertSame(302, $response->status());
        $this->assertSame(1, AffiliateClick::query()->count());

        $click = AffiliateClick::query()->first();
        $this->assertNotNull($click);
        $this->assertSame($link->id, $click->affiliate_link_id);
        $this->assertSame($product->id, $click->product_id);
        $this->assertNull($click->recommendation_session_id);
        $this->assertNull($click->recommendation_result_id);
        $this->assertNull($click->ip_hash);
    }

    public function test_inactive_affiliate_link_returns_not_found(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift(['slug' => 'inactive-link-gift']);
        $link = $product->affiliateLinks->first();
        $link->update(['status' => AffiliateLinkStatus::Inactive]);

        $this->get(DiscoveryUrl::affiliateOut($link->uuid))->assertNotFound();
        $this->assertSame(0, AffiliateClick::query()->count());
    }

    public function test_draft_product_returns_not_found(): void
    {
        $link = $this->affiliateLinkForProductStatus(ProductStatus::Draft, 'draft-out-gift');

        $this->get(DiscoveryUrl::affiliateOut($link->uuid))->assertNotFound();
        $this->assertSame(0, AffiliateClick::query()->count());
    }

    public function test_archived_product_returns_not_found(): void
    {
        $link = $this->affiliateLinkForProductStatus(ProductStatus::Archived, 'archived-out-gift');

        $this->get(DiscoveryUrl::affiliateOut($link->uuid))->assertNotFound();
        $this->assertSame(0, AffiliateClick::query()->count());
    }

    public function test_soft_deleted_product_returns_not_found(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift(['slug' => 'deleted-out-gift']);
        $link = $product->affiliateLinks->first();
        $product->delete();

        $this->get(DiscoveryUrl::affiliateOut($link->uuid))->assertNotFound();
        $this->assertSame(0, AffiliateClick::query()->count());
    }

    public function test_unknown_uuid_returns_not_found(): void
    {
        $this->get(DiscoveryUrl::affiliateOut((string) Str::uuid()))->assertNotFound();
        $this->assertSame(0, AffiliateClick::query()->count());
    }

    public function test_destination_cannot_be_injected_from_request(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift(['slug' => 'safe-redirect-gift']);
        $link = $product->affiliateLinks->first();

        $response = $this->get(DiscoveryUrl::affiliateOut($link->uuid).'?url=https://evil.example/phish');

        $response->assertRedirect($link->url);
        $this->assertSame($link->url, $response->headers->get('Location'));
        $this->assertStringNotContainsString('evil.example', (string) $response->headers->get('Location'));
    }

    public function test_gift_detail_cta_uses_affiliate_out_url(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'CTA Gift',
            'slug' => 'cta-gift',
        ]);
        $link = $product->affiliateLinks->first();
        $outUrl = DiscoveryUrl::affiliateOut($link->uuid);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee('href="'.$outUrl.'"', false)
            ->assertDontSee('href="'.$link->url.'"', false)
            ->assertSee('View at Example Merchant', false);
    }

    public function test_gift_card_cta_uses_affiliate_out_url(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Card CTA Gift',
            'slug' => 'card-cta-gift',
        ]);
        $link = $product->affiliateLinks->first();
        $outUrl = DiscoveryUrl::affiliateOut($link->uuid);

        $html = $this->blade(
            '<x-gift-card :product="$product" />',
            ['product' => $product->fresh(['images', 'affiliateLinks.merchant'])],
        );

        $this->assertStringContainsString('href="'.$outUrl.'"', $html);
        $this->assertStringNotContainsString('href="'.$link->url.'"', $html);
        $this->assertStringContainsString('View at Example Merchant', $html);
    }

    public function test_affiliate_out_route_is_registered(): void
    {
        $this->assertTrue(Route::has('discovery.affiliate.out'));
        $this->assertSame(
            DiscoveryUrl::affiliateOut('550e8400-e29b-41d4-a716-446655440000'),
            '/'.ltrim(route('discovery.affiliate.out', ['uuid' => '550e8400-e29b-41d4-a716-446655440000'], absolute: false), '/'),
        );
    }

    public function test_no_public_products_routes_exist(): void
    {
        $productRoutes = collect(Route::getRoutes())
            ->filter(function ($route): bool {
                $uri = '/'.$route->uri();

                return str_starts_with($uri, '/products') || str_contains($uri, '/products/');
            })
            ->values();

        $this->assertCount(0, $productRoutes);
    }

    private function affiliateLinkForProductStatus(ProductStatus $status, string $slug): AffiliateLink
    {
        $merchant = Merchant::query()->firstOrCreate(
            ['slug' => 'example-merchant'],
            [
                'name' => 'Example Merchant',
                'affiliate_network' => 'example',
                'is_active' => true,
            ],
        );

        $product = Product::factory()->create([
            'slug' => $slug,
            'status' => $status,
            'published_at' => $status === ProductStatus::Published ? now() : null,
        ]);

        return AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://example.com/'.$slug,
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);
    }
}
