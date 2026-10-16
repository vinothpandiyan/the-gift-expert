<?php

namespace Tests\Feature\Discovery;

use App\Actions\Discovery\QueryDiscoveryListingProductsAction;
use App\Actions\Discovery\ResolveDiscoveryFilterOptionsAction;
use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Enums\AffiliateLinkStatus;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingActiveAffiliateAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private Relationship $husband;

    private Occasion $birthday;

    private Product $active;

    private Product $lapsed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->husband = Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true, 'sort_order' => 1]);
        $this->birthday = Occasion::query()->create(['name' => 'Birthday', 'slug' => 'birthday', 'is_active' => true, 'sort_order' => 1]);

        $this->active = $this->gift('active-gift');
        $this->lapsed = $this->gift('lapsed-gift');
    }

    public function test_flag_off_keeps_current_listing_behavior_even_when_a_link_has_lapsed(): void
    {
        $this->lapseLink($this->lapsed);
        config(['discovery_ranking.listing_require_active_affiliate' => false]);

        $this->assertEqualsCanonicalizing(
            [$this->active->id, $this->lapsed->id],
            $this->listingIds(DiscoveryListingQueryState::SORT_RECOMMENDED),
        );
        $this->assertSame(2, $this->listingTotal());
    }

    public function test_flag_on_removes_lapsed_products_from_listing_total_facets_and_every_sort(): void
    {
        $this->lapseLink($this->lapsed);
        config(['discovery_ranking.listing_require_active_affiliate' => true]);

        foreach ([
            DiscoveryListingQueryState::SORT_RECOMMENDED,
            DiscoveryListingQueryState::SORT_NEWEST,
            DiscoveryListingQueryState::SORT_PRICE_ASC,
            DiscoveryListingQueryState::SORT_PRICE_DESC,
        ] as $sort) {
            $this->assertSame([$this->active->id], $this->listingIds($sort), "sort {$sort}");
        }

        $this->assertSame(1, $this->listingTotal());

        $options = app(ResolveDiscoveryFilterOptionsAction::class)->execute(
            DiscoveryListingContext::forRelationship($this->husband),
            new DiscoveryListingQueryState,
        );

        $this->assertSame(1, $options['occasion']->firstWhere('slug', 'birthday')?->count);
    }

    public function test_flag_on_is_a_no_op_when_every_published_product_has_an_active_link(): void
    {
        config(['discovery_ranking.listing_require_active_affiliate' => false]);
        $before = $this->snapshot();

        config(['discovery_ranking.listing_require_active_affiliate' => true]);
        $after = $this->snapshot();

        $this->assertSame($before, $after);
    }

    public function test_relinking_restores_the_product_when_the_flag_is_on(): void
    {
        config(['discovery_ranking.listing_require_active_affiliate' => true]);
        $this->lapseLink($this->lapsed);
        $this->assertSame(1, $this->listingTotal());

        $this->lapsed->affiliateLinks()->update(['status' => AffiliateLinkStatus::Active]);

        $this->assertSame(2, $this->listingTotal());
    }

    public function test_seo_and_publication_surfaces_do_not_read_the_listing_flag(): void
    {
        $page = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-husband',
            'is_indexable' => true,
            'include_in_sitemap' => true,
        ]);
        $this->lapseLink($this->lapsed);

        config(['discovery_ranking.listing_require_active_affiliate' => false]);
        $off = $this->get(DiscoveryUrl::sitemap())->assertOk()->getContent();

        config(['discovery_ranking.listing_require_active_affiliate' => true]);
        $on = $this->get(DiscoveryUrl::sitemap())->assertOk()->getContent();

        $this->assertSame($off, $on);
        $this->assertStringContainsString(DiscoveryUrl::seoLandingPage($page->slug, absolute: true), $on);

        // The shared query action stays strict-by-default for SEO and coverage callers.
        $this->assertSame(
            2,
            app(QueryPublishedProductsByFiltersAction::class)
                ->execute(['relationship_id' => $this->husband->id])
                ->count(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $snapshot = ['total' => $this->listingTotal()];

        foreach ([
            DiscoveryListingQueryState::SORT_RECOMMENDED,
            DiscoveryListingQueryState::SORT_NEWEST,
            DiscoveryListingQueryState::SORT_PRICE_ASC,
        ] as $sort) {
            $snapshot[$sort] = $this->listingIds($sort);
        }

        $snapshot['facets'] = app(ResolveDiscoveryFilterOptionsAction::class)
            ->execute(DiscoveryListingContext::forRelationship($this->husband), new DiscoveryListingQueryState)['occasion']
            ->mapWithKeys(fn ($option): array => [$option->slug => $option->count])
            ->all();

        return $snapshot;
    }

    private function gift(string $slug): Product
    {
        static $price = 100;
        $price += 50;

        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'price_amount' => (string) $price,
            'price_currency' => 'INR',
        ]);
        $product->relationships()->attach($this->husband->id);
        $product->occasions()->attach($this->birthday->id);

        return $product;
    }

    private function lapseLink(Product $product): void
    {
        $product->affiliateLinks()->update(['status' => AffiliateLinkStatus::Inactive]);
    }

    /**
     * @return list<int>
     */
    private function listingIds(string $sort): array
    {
        $state = new DiscoveryListingQueryState(sort: $sort);

        return app(QueryDiscoveryListingProductsAction::class)
            ->execute(DiscoveryListingContext::forRelationship($this->husband), $state)
            ->getCollection()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function listingTotal(): int
    {
        return app(QueryDiscoveryListingProductsAction::class)
            ->count(DiscoveryListingContext::forRelationship($this->husband), new DiscoveryListingQueryState);
    }
}
