<?php

namespace Tests\Feature\Discovery;

use App\Support\DiscoveryUrl;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoutePrefixConfigTest extends TestCase
{
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

    public function test_discovery_routes_match_configured_urls(): void
    {
        $this->assertSame(
            DiscoveryUrl::gift('example-gift'),
            '/'.ltrim(route('discovery.gift.show', ['slug' => 'example-gift'], absolute: false), '/'),
        );

        $this->assertSame(
            DiscoveryUrl::giftIdeasCategory('a/b'),
            '/'.ltrim(route('discovery.gift_ideas.category', ['full_path' => 'a/b'], absolute: false), '/'),
        );

        $this->assertTrue(Route::has('discovery.finder.show'));
        $this->assertTrue(Route::has('discovery.finder.results'));
        $this->assertSame(
            DiscoveryUrl::finder(),
            '/'.ltrim(route('discovery.finder.show', absolute: false), '/'),
        );
        $this->assertSame(
            DiscoveryUrl::finderResults('550e8400-e29b-41d4-a716-446655440000'),
            '/'.ltrim(route('discovery.finder.results', ['uuid' => '550e8400-e29b-41d4-a716-446655440000'], absolute: false), '/'),
        );
        $this->assertTrue(Route::has('discovery.affiliate.out'));
        $this->assertSame(
            DiscoveryUrl::affiliateOut('550e8400-e29b-41d4-a716-446655440000'),
            '/'.ltrim(route('discovery.affiliate.out', ['uuid' => '550e8400-e29b-41d4-a716-446655440000'], absolute: false), '/'),
        );
        $this->assertTrue(Route::has('discovery.seo_landing.show'));
        $this->assertSame(
            DiscoveryUrl::seoLandingPage('birthday-gifts-for-husband'),
            '/'.ltrim(route('discovery.seo_landing.show', ['slug' => 'birthday-gifts-for-husband'], absolute: false), '/'),
        );
    }
}
