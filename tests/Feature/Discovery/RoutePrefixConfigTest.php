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

        $this->assertFalse(Route::has('discovery.finder.show'));
        $this->assertFalse(Route::has('discovery.affiliate.out'));
    }
}
