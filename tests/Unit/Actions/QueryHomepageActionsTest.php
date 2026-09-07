<?php

namespace Tests\Unit\Actions;

use App\Actions\Home\QueryFeaturedHomepageGiftsAction;
use App\Actions\Home\QueryHomepageInspirationPagesAction;
use App\Actions\Home\QueryHomepageTaxonomiesAction;
use App\Enums\AffiliateLinkStatus;
use App\Models\GiftType;
use App\Models\Product;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Discovery\GiftCatalogTestHelpers;
use Tests\TestCase;

class QueryHomepageActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_featured_query_returns_published_featured_gifts_with_active_affiliates_only(): void
    {
        $kept = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Kept Featured',
            'slug' => 'kept-featured',
            'is_featured' => true,
            'published_at' => now()->subDay(),
        ]);
        GiftCatalogTestHelpers::publishedGift([
            'name' => 'Not Featured',
            'slug' => 'not-featured',
            'is_featured' => false,
        ]);
        Product::factory()->draft()->create([
            'name' => 'Draft Featured',
            'slug' => 'draft-featured',
            'is_featured' => true,
        ]);
        $inactive = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Inactive Featured',
            'slug' => 'inactive-featured',
            'is_featured' => true,
        ]);
        $inactive->affiliateLinks()->update(['status' => AffiliateLinkStatus::Inactive]);

        $results = app(QueryFeaturedHomepageGiftsAction::class)->execute();

        $this->assertSame([$kept->id], $results->pluck('id')->all());
        $this->assertTrue($results->first()->relationLoaded('images'));
        $this->assertTrue($results->first()->relationLoaded('affiliateLinks'));
    }

    public function test_featured_query_caps_results(): void
    {
        foreach (range(1, 10) as $index) {
            GiftCatalogTestHelpers::publishedGift([
                'name' => 'Featured '.$index,
                'slug' => 'featured-'.$index,
                'is_featured' => true,
                'published_at' => now()->subMinutes($index),
            ]);
        }

        $this->assertCount(8, app(QueryFeaturedHomepageGiftsAction::class)->execute());
    }

    public function test_homepage_taxonomies_omit_inactive_rows_and_missing_return_gifts(): void
    {
        Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true, 'sort_order' => 1]);
        Relationship::query()->create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false, 'sort_order' => 2]);

        $taxonomies = app(QueryHomepageTaxonomiesAction::class)->execute();

        $this->assertSame(['Husband'], $taxonomies['relationships']->pluck('name')->all());
        $this->assertNull($taxonomies['returnGifts']);

        GiftType::query()->create(['name' => 'Return Gifts', 'slug' => 'return-gifts', 'is_active' => true]);

        $this->assertSame(
            'return-gifts',
            app(QueryHomepageTaxonomiesAction::class)->execute()['returnGifts']?->slug,
        );
    }

    public function test_inspiration_query_returns_discoverable_pages_only(): void
    {
        $kept = SeoLandingPage::factory()->published()->create([
            'heading' => 'Visible Guide',
            'slug' => 'visible-guide',
            'is_indexable' => true,
            'sort_order' => 1,
        ]);
        SeoLandingPage::factory()->published()->create([
            'heading' => 'Noindex Guide',
            'slug' => 'noindex-guide',
            'is_indexable' => false,
            'sort_order' => 2,
        ]);
        SeoLandingPage::factory()->draft()->create([
            'heading' => 'Draft Guide',
            'slug' => 'draft-guide',
            'is_indexable' => true,
        ]);

        $results = app(QueryHomepageInspirationPagesAction::class)->execute();

        $this->assertSame([$kept->id], $results->pluck('id')->all());
    }
}
