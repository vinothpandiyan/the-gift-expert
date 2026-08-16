<?php

namespace Tests\Unit;

use App\Support\DiscoveryUrl;
use InvalidArgumentException;
use Tests\TestCase;

class DiscoveryUrlTest extends TestCase
{
    public function test_gift_url_uses_configured_prefix(): void
    {
        $this->assertSame('/gifts/personalized-wooden-photo-frame', DiscoveryUrl::gift('personalized-wooden-photo-frame'));
    }

    public function test_gift_ideas_category_url_supports_hierarchical_paths(): void
    {
        $this->assertSame(
            '/gift-ideas/gifts-for-him/gifts-for-husband',
            DiscoveryUrl::giftIdeasCategory('gifts-for-him/gifts-for-husband'),
        );
        $this->assertSame('/gift-ideas', DiscoveryUrl::giftIdeas());
    }

    public function test_taxonomy_urls_use_configured_prefixes(): void
    {
        $this->assertSame('/occasions/birthday', DiscoveryUrl::occasion('birthday'));
        $this->assertSame('/gifts-for/husband', DiscoveryUrl::relationship('husband'));
        $this->assertSame('/recipients/kids', DiscoveryUrl::recipientType('kids'));
        $this->assertSame('/interests/technology', DiscoveryUrl::interest('technology'));
        $this->assertSame('/professions/software-developer', DiscoveryUrl::profession('software-developer'));
        $this->assertSame('/gift-types/gift-cards', DiscoveryUrl::giftType('gift-cards'));
    }

    public function test_finder_and_affiliate_urls_use_configured_paths(): void
    {
        $this->assertSame('/find-a-gift', DiscoveryUrl::finder());
        $this->assertSame('/find-a-gift/results/550e8400-e29b-41d4-a716-446655440000', DiscoveryUrl::finderResults('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertSame('/out/550e8400-e29b-41d4-a716-446655440000', DiscoveryUrl::affiliateOut('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertSame('/birthday-gifts-for-husband', DiscoveryUrl::seoLandingPage('birthday-gifts-for-husband'));
        $this->assertSame('/gifts-for-boyfriend', DiscoveryUrl::seoLandingPage('gifts-for-boyfriend'));
        $this->assertSame('/sitemap.xml', DiscoveryUrl::sitemap());
    }

    public function test_absolute_urls_prefix_with_app_url(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->assertSame(
            'http://localhost/gifts/personalized-wooden-photo-frame',
            DiscoveryUrl::gift('personalized-wooden-photo-frame', absolute: true),
        );
    }

    public function test_urls_reflect_overridden_config_prefixes(): void
    {
        config([
            'discovery.routes' => array_merge(config('discovery.routes', []), [
                'gift.show' => '/presents/{slug}',
                'relationship.show' => '/for/{slug}',
            ]),
        ]);

        $this->assertSame('/presents/example-gift', DiscoveryUrl::gift('example-gift'));
        $this->assertSame('/for/husband', DiscoveryUrl::relationship('husband'));
    }

    public function test_unknown_route_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Discovery route [missing.route] is not configured.');

        DiscoveryUrl::route('missing.route');
    }

    public function test_unresolved_placeholders_throw_exception(): void
    {
        config([
            'discovery.routes' => array_merge(config('discovery.routes', []), [
                'gift.show' => '/gifts/{slug}/{extra}',
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Discovery route [gift.show] has unresolved placeholders.');

        DiscoveryUrl::gift('example-gift');
    }
}
