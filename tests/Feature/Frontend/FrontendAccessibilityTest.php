<?php

namespace Tests\Feature\Frontend;

use App\Models\Occasion;
use App\Models\RecommendationSession;
use App\Models\Relationship;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Discovery\GiftCatalogTestHelpers;
use Tests\TestCase;

class FrontendAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_include_a_skip_link_and_main_landmark(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="#content"', $html);
        $this->assertStringContainsString('Skip to content', $html);
        $this->assertMatchesRegularExpression('/<main[^>]*id="content"/', $html);
    }

    public function test_core_pages_render_exactly_one_h1(): void
    {
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);
        $gift = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Accessible Wallet',
            'slug' => 'accessible-wallet',
        ]);
        $gift->relationships()->attach($husband);
        $session = RecommendationSession::query()->create([]);

        $pages = [
            '/',
            DiscoveryUrl::giftIdeas(),
            DiscoveryUrl::relationship('husband'),
            DiscoveryUrl::gift($gift->slug),
            DiscoveryUrl::finder(),
            DiscoveryUrl::finderResults($session->uuid),
        ];

        foreach ($pages as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame(1, preg_match_all('/<h1\b/i', $html), 'Expected one H1 on '.$url);
        }
    }

    public function test_listing_filter_drawer_exposes_dialog_semantics(): void
    {
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);
        Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);
        $gift = GiftCatalogTestHelpers::publishedGift(['name' => 'Filter Gift', 'slug' => 'filter-gift']);
        $gift->relationships()->attach($husband);
        $gift->occasions()->attach(Occasion::query()->where('slug', 'birthday')->first());

        $html = $this->get(DiscoveryUrl::relationship('husband'))->assertOk()->getContent();

        $this->assertStringContainsString('aria-controls="gift-listing-filter-drawer"', $html);
        $this->assertStringContainsString('id="gift-listing-filter-drawer"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-label="Filters"', $html);
        $this->assertStringContainsString('aria-label="Close filters"', $html);
        $this->assertStringContainsString('aria-controls="desktop-occasion-panel"', $html);
        $this->assertStringContainsString('id="desktop-occasion-panel"', $html);
        $this->assertStringContainsString('id="desktop-occasion-heading"', $html);
        $this->assertStringContainsString('for="desktop-occasion-birthday"', $html);
        $this->assertStringContainsString('form-control-checkbox', $html);
        $this->assertStringContainsString('Birthday, 1 gift idea', $html);
        $this->assertStringContainsString('aria-busy="true"', $html);
    }

    public function test_finder_exposes_progress_and_option_semantics(): void
    {
        Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);

        $html = $this->get(DiscoveryUrl::finder())->assertOk()->getContent();

        $this->assertStringContainsString('role="progressbar"', $html);
        $this->assertStringContainsString('aria-valuenow="1"', $html);
        $this->assertStringContainsString('role="radiogroup"', $html);
        $this->assertStringContainsString('role="radio"', $html);
        $this->assertStringContainsString('aria-checked="false"', $html);
    }

    public function test_gift_detail_outbound_links_include_new_tab_screen_reader_text(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Outbound Gift',
            'slug' => 'outbound-gift',
        ]);

        $html = $this->get(DiscoveryUrl::gift($product->slug))->assertOk()->getContent();

        $this->assertStringContainsString('(opens in a new tab)', $html);
        $this->assertStringContainsString(DiscoveryUrl::affiliateOut($product->affiliateLinks->first()->uuid), $html);
    }

    public function test_mobile_nav_dialog_has_close_control(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Open menu"', $html);
        $this->assertStringContainsString('aria-label="Close menu"', $html);
        $this->assertStringContainsString('id="mobile-primary-nav"', $html);
        $this->assertStringContainsString('aria-controls="mobile-primary-nav"', $html);
    }

    public function test_taxonomy_listing_heading_order_includes_results_h2(): void
    {
        $occasion = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);
        GiftCatalogTestHelpers::publishedGift(['name' => 'Party Hat', 'slug' => 'party-hat'])
            ->occasions()
            ->attach($occasion);

        $html = $this->get(DiscoveryUrl::occasion('birthday'))->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
        $this->assertGreaterThanOrEqual(1, preg_match_all('/<h2\b/i', $html));
        $this->assertMatchesRegularExpression('/<h2[^>]*>\s*1\s+gift idea\s*<\/h2>/i', $html);
    }
}
