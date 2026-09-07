<?php

namespace Tests\Feature\Home;

use App\Enums\AffiliateLinkStatus;
use App\Models\BudgetRange;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use App\Support\PageMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Discovery\GiftCatalogTestHelpers;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_h1_and_primary_ctas(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<h1', false)
            ->assertSee("Find a gift they'll actually love.", false)
            ->assertSee('href="'.DiscoveryUrl::finder().'"', false)
            ->assertSee('Find a Gift', false)
            ->assertSee('href="'.DiscoveryUrl::giftIdeas().'"', false)
            ->assertSee('Browse Gift Ideas', false)
            ->assertSee('<link rel="canonical" href="'.PageMeta::homeCanonical().'">', false)
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertDontSee('href="/blog', false);
    }

    public function test_homepage_selector_posts_to_finder_with_taxonomy_query_keys(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('action="'.DiscoveryUrl::finder().'"', false)
            ->assertSee('name="relationship"', false)
            ->assertSee('name="occasion"', false)
            ->assertSee('name="budget"', false)
            ->assertSee('Show me gifts', false);
    }

    public function test_homepage_lists_active_recipients_and_occasions_only(): void
    {
        Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true, 'sort_order' => 1]);
        Relationship::query()->create(['name' => 'Hidden Relative', 'slug' => 'hidden-relative', 'is_active' => false, 'sort_order' => 2]);
        Occasion::query()->create(['name' => 'Birthday', 'slug' => 'birthday', 'is_active' => true, 'sort_order' => 1]);
        Occasion::query()->create(['name' => 'Secret Day', 'slug' => 'secret-day', 'is_active' => false, 'sort_order' => 2]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.DiscoveryUrl::relationship('husband').'"', $html);
        $this->assertStringContainsString('href="'.DiscoveryUrl::occasion('birthday').'"', $html);
        $this->assertStringNotContainsString('href="'.DiscoveryUrl::relationship('hidden-relative').'"', $html);
        $this->assertStringNotContainsString('Hidden Relative', $html);
        $this->assertStringNotContainsString('Secret Day', $html);
    }

    public function test_homepage_budget_links_use_gift_ideas_query(): void
    {
        BudgetRange::query()->create([
            'name' => 'Under ₹500',
            'slug' => 'under-500',
            'min_amount' => null,
            'max_amount' => '499.99',
            'currency' => 'INR',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.DiscoveryUrl::giftIdeasQuery(['budget' => 'under-500']).'"', false)
            ->assertDontSee('/budgets/', false);
    }

    public function test_homepage_shows_featured_published_gifts_only(): void
    {
        $featured = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Featured Wallet',
            'slug' => 'featured-wallet',
            'is_featured' => true,
        ]);
        GiftCatalogTestHelpers::publishedGift([
            'name' => 'Ordinary Mug',
            'slug' => 'ordinary-mug',
            'is_featured' => false,
        ]);
        Product::factory()->draft()->create([
            'name' => 'Draft Featured',
            'slug' => 'draft-featured',
            'is_featured' => true,
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Featured Wallet', $html);
        $this->assertStringContainsString('href="'.DiscoveryUrl::gift($featured->slug).'"', $html);
        $this->assertStringNotContainsString('Ordinary Mug', $html);
        $this->assertStringNotContainsString('Draft Featured', $html);
        $this->assertStringNotContainsString('/out/', $html);
        $this->assertStringContainsString('Trending gift ideas', $html);
    }

    public function test_homepage_omits_trending_section_when_no_featured_gifts_exist(): void
    {
        GiftCatalogTestHelpers::publishedGift([
            'name' => 'Plain Gift',
            'slug' => 'plain-gift',
            'is_featured' => false,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Trending gift ideas', false)
            ->assertDontSee('Plain Gift', false);
    }

    public function test_homepage_excludes_featured_gifts_without_an_active_affiliate(): void
    {
        $gift = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Orphan Featured',
            'slug' => 'orphan-featured',
            'is_featured' => true,
        ]);
        $gift->affiliateLinks()->update(['status' => AffiliateLinkStatus::Inactive]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Orphan Featured', false)
            ->assertDontSee('Trending gift ideas', false);
    }

    public function test_return_gifts_block_renders_only_when_taxonomy_exists(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('Return gift ideas', false);

        GiftType::query()->create([
            'name' => 'Return Gifts',
            'slug' => 'return-gifts',
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Return gift ideas', false)
            ->assertSee('href="'.DiscoveryUrl::giftType('return-gifts').'"', false);
    }

    public function test_inspiration_cards_use_discoverable_landing_pages_only(): void
    {
        $published = SeoLandingPage::factory()->published()->create([
            'heading' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'intro_content' => 'Ideas that feel personal.',
            'is_indexable' => true,
            'sort_order' => 1,
        ]);
        SeoLandingPage::factory()->draft()->create([
            'heading' => 'Draft Guide',
            'slug' => 'draft-guide',
            'is_indexable' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Birthday Gifts for Husband', false)
            ->assertSee('href="'.DiscoveryUrl::seoLandingPage($published->slug).'"', false)
            ->assertDontSee('Draft Guide', false)
            ->assertDontSee('href="/blog', false);
    }

    public function test_homepage_interest_links_use_discovery_urls(): void
    {
        Interest::query()->create(['name' => 'Coffee', 'slug' => 'coffee', 'is_active' => true]);
        Interest::query()->create(['name' => 'Hidden Hobby', 'slug' => 'hidden-hobby', 'is_active' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.DiscoveryUrl::interest('coffee').'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::interest('hidden-hobby').'"', false);
    }

    public function test_homepage_query_count_stays_bounded(): void
    {
        Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true, 'sort_order' => 1]);
        Occasion::query()->create(['name' => 'Birthday', 'slug' => 'birthday', 'is_active' => true, 'sort_order' => 1]);
        GiftCatalogTestHelpers::publishedGift([
            'name' => 'Featured Bound',
            'slug' => 'featured-bound',
            'is_featured' => true,
        ]);

        $this->get('/')->assertOk();

        $count = $this->countQueries(fn () => $this->get('/')->assertOk());

        $this->assertLessThanOrEqual(40, $count);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();

        return count(DB::getQueryLog());
    }
}
