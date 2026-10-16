<?php

namespace Tests\Feature\RecipientEligibility;

use App\Actions\Discovery\QueryDiscoveryListingProductsAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Models\Relationship;
use Tests\Support\SeedsRecipientEligibilityCatalog;
use Tests\TestCase;

class RecipientEligibilityPoolOrderingTest extends TestCase
{
    use SeedsRecipientEligibilityCatalog;

    /** @var list<int> */
    private array $strongInferred = [];

    /** @var list<int> */
    private array $genericExplicit = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedEligibilityTaxonomy();
        config(['discovery_ranking.enabled' => true, 'discovery_ranking.candidate_pool_max' => 8]);

        // Older, strongly relevant inferred gifts: primary category match and featured.
        for ($i = 1; $i <= 6; $i++) {
            $this->strongInferred[] = $this->eligibilityProduct('Strong Inferred '.$i, [
                'occasions' => ['birthday'], 'genders' => ['unisex'], 'categories' => ['sports-and-outdoors'],
            ], ['is_featured' => true, 'published_at' => now()->subDays(100 + $i)])->id;
        }

        // Newer, generic explicit gifts: only a secondary category match, no price.
        for ($i = 1; $i <= 14; $i++) {
            $this->genericExplicit[] = $this->eligibilityProduct('Generic Explicit '.$i, [
                'relationships' => ['husband'], 'occasions' => ['birthday'], 'categories' => ['home-and-living', 'sports-and-outdoors'],
            ], ['price_amount' => null, 'published_at' => now()->subDays($i)])->id;
        }
    }

    public function test_recency_ordering_can_drop_strongly_relevant_inferred_gifts_from_the_pool(): void
    {
        $this->enableRecipientEligibility(['pool_ordering' => false]);

        $firstPage = $this->firstPageIds();

        $this->assertEmpty(array_intersect($firstPage, $this->strongInferred), 'baseline behavior: the pool is the newest products');
    }

    public function test_relevance_first_ordering_retains_strong_inferred_candidates_over_generic_explicit_ones(): void
    {
        $this->enableRecipientEligibility(['pool_ordering' => true]);

        $firstPage = $this->firstPageIds();

        $this->assertEqualsCanonicalizing($this->strongInferred, array_values(array_intersect($firstPage, $this->strongInferred)));
        $this->assertCount(12, $firstPage);
    }

    public function test_totals_and_pagination_are_complete_regardless_of_pool_size_or_ordering(): void
    {
        foreach ([false, true] as $ordering) {
            $this->enableRecipientEligibility(['pool_ordering' => $ordering]);

            $total = 6 + 14;
            $ids = [];

            foreach ([1, 2] as $page) {
                $paginator = $this->listing()->execute($this->context(), $this->state(), $page);
                $this->assertSame($total, $paginator->total());
                $ids = array_merge($ids, $paginator->getCollection()->pluck('id')->all());
            }

            $this->assertCount($total, array_unique($ids), 'every eligible gift is reachable exactly once');
        }
    }

    public function test_explicit_matches_are_never_dropped_from_results_by_relevance_ordering(): void
    {
        $this->enableRecipientEligibility(['pool_ordering' => true]);

        $all = $this->listing()->execute($this->context(), $this->state(), 1, 2)->getCollection()->pluck('id')->all();

        $this->assertEmpty(array_diff($this->genericExplicit, $all));
    }

    public function test_pool_ordering_flag_off_leaves_the_default_recency_ordering(): void
    {
        $this->enableRecipientEligibility(['pool_ordering' => false, 'enabled' => false]);

        $this->assertEmpty(array_intersect($this->firstPageIds(), $this->strongInferred));
    }

    /**
     * @return list<int>
     */
    private function firstPageIds(): array
    {
        return $this->listing()->execute($this->context(), $this->state())->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function listing(): QueryDiscoveryListingProductsAction
    {
        return app(QueryDiscoveryListingProductsAction::class);
    }

    private function context(): DiscoveryListingContext
    {
        return DiscoveryListingContext::forRelationship(Relationship::query()->where('slug', 'husband')->firstOrFail());
    }

    private function state(): DiscoveryListingQueryState
    {
        return new DiscoveryListingQueryState(categoryPaths: ['sports-and-outdoors']);
    }
}
