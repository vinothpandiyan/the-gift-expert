<?php

namespace Tests\Feature\RecipientEligibility;

use App\Actions\Discovery\QueryDiscoveryListingProductsAction;
use App\Actions\Discovery\ResolveDiscoveryFilterOptionsAction;
use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\RecipientEligibility\RecipientEligibilityConfig;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsRecipientEligibilityCatalog;
use Tests\TestCase;

class RecipientEligibilityListingTest extends TestCase
{
    use SeedsRecipientEligibilityCatalog;

    private Product $dumbbell;

    private Product $explicitNoGender;

    private Product $explicitHusbandWife;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedEligibilityTaxonomy();

        $this->dumbbell = $this->eligibilityProduct('Hex Dumbbell Pair', [
            'occasions' => ['birthday'],
            'interests' => ['fitness'],
            'genders' => ['unisex'],
            'categories' => ['sports-and-outdoors'],
        ]);
        $this->explicitNoGender = $this->eligibilityProduct('Husband Mug', [
            'relationships' => ['husband'],
            'occasions' => ['birthday'],
            'categories' => ['home-and-living'],
        ]);
        $this->explicitHusbandWife = $this->eligibilityProduct('Couple Watch Stand', [
            'relationships' => ['husband', 'wife'],
            'occasions' => ['birthday'],
            'genders' => ['unisex'],
        ]);
    }

    public function test_master_flag_off_keeps_explicit_only_behavior_even_with_every_other_flag_on(): void
    {
        $this->enableRecipientEligibility(['enabled' => false]);

        $this->assertEqualsCanonicalizing(
            [$this->explicitNoGender->id, $this->explicitHusbandWife->id],
            $this->husbandIds(),
        );
    }

    public function test_enabled_without_surface_or_relationship_flags_is_strict(): void
    {
        $this->enableRecipientEligibility(['surfaces.relationship' => 'off']);
        $this->assertCount(2, $this->husbandIds());

        $this->enableRecipientEligibility(['relationships.husband.infer' => false]);
        $this->assertCount(2, $this->husbandIds());
    }

    public function test_general_purpose_gift_is_retained_for_husband_when_inference_is_on(): void
    {
        $this->enableRecipientEligibility();

        $ids = $this->husbandIds();

        $this->assertContains($this->dumbbell->id, $ids);
        $this->assertContains($this->explicitNoGender->id, $ids, 'explicit tags with missing gender stay eligible');
        $this->assertContains($this->explicitHusbandWife->id, $ids);
        $this->assertCount(3, $ids);
    }

    public function test_inferred_products_are_excluded_when_a_guard_blocks_them(): void
    {
        $blocked = [
            'female only' => $this->eligibilityProduct('Women Bag', ['occasions' => ['birthday'], 'genders' => ['female']]),
            'gender missing' => $this->eligibilityProduct('No Gender Gift', ['occasions' => ['birthday']]),
            'child exclusive' => $this->eligibilityProduct('Kids Puzzle', ['occasions' => ['birthday'], 'genders' => ['unisex'], 'recipient_types' => ['kids'], 'categories' => ['electronics']]),
            'toys category' => $this->eligibilityProduct('Board Game', ['occasions' => ['birthday'], 'genders' => ['unisex'], 'categories' => ['toys-and-games']]),
            'baby shower only' => $this->eligibilityProduct('Baby Rattle', ['occasions' => ['baby-shower'], 'genders' => ['unisex']]),
            'no occasion' => $this->eligibilityProduct('Occasionless', ['genders' => ['unisex']]),
            'return gift' => $this->eligibilityProduct('Return Hamper', ['occasions' => ['birthday'], 'genders' => ['unisex'], 'gift_types' => ['return-gifts']]),
            'tagged elsewhere' => $this->eligibilityProduct('Friends Only', ['relationships' => ['friends'], 'occasions' => ['birthday'], 'genders' => ['unisex']]),
        ];

        $this->enableRecipientEligibility();
        $ids = $this->husbandIds();

        foreach ($blocked as $reason => $product) {
            $this->assertNotContains($product->id, $ids, $reason);
        }
    }

    public function test_family_products_spanning_adult_life_stages_are_not_treated_as_child_exclusive(): void
    {
        $family = $this->eligibilityProduct('Family Board Game', [
            'occasions' => ['birthday'],
            'genders' => ['unisex'],
            'recipient_types' => ['kids', 'college-student'],
            'categories' => ['electronics'],
        ]);

        $this->enableRecipientEligibility();

        $this->assertContains($family->id, $this->husbandIds());
    }

    public function test_gender_is_respected_per_relationship(): void
    {
        $womenBag = $this->eligibilityProduct('Women Bag', ['occasions' => ['birthday'], 'genders' => ['female']]);

        $this->enableRecipientEligibility();

        $this->assertNotContains($womenBag->id, $this->idsFor('husband'));
        $this->assertNotContains($womenBag->id, $this->idsFor('boyfriend'));
        $this->assertContains($womenBag->id, $this->idsFor('wife'));
        $this->assertContains($womenBag->id, $this->idsFor('girlfriend'));
    }

    public function test_unisex_products_are_not_inferred_for_unapproved_relationships_or_recipient_types(): void
    {
        $this->enableRecipientEligibility([
            'relationships.brother.infer' => true,
            'relationships.friends.infer' => true,
            'relationships.father.infer' => true,
        ]);

        foreach (['brother', 'sister', 'friends', 'father', 'son', 'mother', 'parents', 'boss', 'colleagues', 'newlyweds', 'grandparents', 'daughter'] as $slug) {
            $this->assertNotContains($this->dumbbell->id, $this->idsFor($slug), $slug);
        }

        $kids = DiscoveryListingContext::forRecipientType(RecipientType::query()->where('slug', 'kids')->firstOrFail());
        $ids = app(QueryDiscoveryListingProductsAction::class)
            ->execute($kids, new DiscoveryListingQueryState)
            ->getCollection()->pluck('id')->all();

        $this->assertNotContains($this->dumbbell->id, $ids);
    }

    public function test_inference_creates_no_relationship_pivot_rows(): void
    {
        $before = DB::table('relationship_product')->count();

        $this->enableRecipientEligibility();
        $this->husbandIds();
        $this->listingTotal('husband');

        $this->assertSame($before, DB::table('relationship_product')->count());
        $this->assertDatabaseMissing('relationship_product', [
            'product_id' => $this->dumbbell->id,
            'relationship_id' => $this->relationshipId('husband'),
        ]);
    }

    public function test_explicit_products_rank_above_inferred_under_recommended_sort(): void
    {
        $this->enableRecipientEligibility();

        $ids = $this->husbandIds();

        $this->assertSame($this->dumbbell->id, $ids[2], 'the inferred product is listed after both explicit matches');
    }

    public function test_every_sort_returns_the_same_set_and_totals_agree_with_count_and_pagination(): void
    {
        config(['discovery_ranking.per_page' => 4]);

        for ($i = 1; $i <= 11; $i++) {
            $this->eligibilityProduct('Generic Gift '.$i, ['occasions' => ['birthday'], 'genders' => ['unisex']]);
        }

        $this->enableRecipientEligibility();

        $expected = $this->husbandIds();
        $this->assertCount(14, $expected);
        $this->assertSame(14, $this->listingTotal('husband'));

        foreach ([
            DiscoveryListingQueryState::SORT_NEWEST,
            DiscoveryListingQueryState::SORT_PRICE_ASC,
            DiscoveryListingQueryState::SORT_PRICE_DESC,
        ] as $sort) {
            $this->assertEqualsCanonicalizing($expected, $this->husbandIds($sort), $sort);
        }

        // Paging window by window: no duplicates and no gaps.
        foreach ([DiscoveryListingQueryState::SORT_RECOMMENDED, DiscoveryListingQueryState::SORT_NEWEST] as $sort) {
            $seen = [];
            $context = DiscoveryListingContext::forRelationship(Relationship::query()->where('slug', 'husband')->firstOrFail());

            foreach ([1, 2, 3, 4] as $page) {
                $paginator = app(QueryDiscoveryListingProductsAction::class)
                    ->execute($context, new DiscoveryListingQueryState(sort: $sort), $page);
                $this->assertSame(14, $paginator->total());
                array_push($seen, ...$paginator->getCollection()->pluck('id')->all());
            }

            $this->assertCount(14, array_unique($seen), "{$sort}: no duplicates, no gaps");
            $this->assertEqualsCanonicalizing($expected, $seen);
        }
    }

    public function test_relationship_chip_count_equals_the_listing_total_after_selecting_it(): void
    {
        $this->enableRecipientEligibility();

        $birthday = Occasion::query()->where('slug', 'birthday')->firstOrFail();
        $context = DiscoveryListingContext::forOccasion($birthday);

        $options = app(ResolveDiscoveryFilterOptionsAction::class)->execute($context, new DiscoveryListingQueryState);
        $chip = $options['relationship']->firstWhere('slug', 'husband');

        $selected = new DiscoveryListingQueryState(relationshipSlugs: ['husband']);
        $total = app(QueryDiscoveryListingProductsAction::class)->count($context, $selected);

        $this->assertSame(3, $total);
        $this->assertSame($total, $chip?->count);
        $this->assertSame(
            $total,
            app(QueryDiscoveryListingProductsAction::class)->execute($context, $selected)->total(),
        );
    }

    public function test_other_dimension_facets_follow_the_selected_relationship_eligibility(): void
    {
        $this->enableRecipientEligibility();

        $context = DiscoveryListingContext::forRelationship(Relationship::query()->where('slug', 'husband')->firstOrFail());
        $options = app(ResolveDiscoveryFilterOptionsAction::class)->execute($context, new DiscoveryListingQueryState);

        $fitness = $options['interest']->firstWhere('slug', 'fitness');
        $this->assertSame(1, $fitness?->count, 'the inferred dumbbell is counted under Fitness');
    }

    public function test_fitness_interest_refinement_surfaces_the_inferred_dumbbell(): void
    {
        $this->enableRecipientEligibility();

        $fitness = Interest::query()->where('slug', 'fitness')->firstOrFail();
        $context = DiscoveryListingContext::forRelationship(Relationship::query()->where('slug', 'husband')->firstOrFail());
        $state = new DiscoveryListingQueryState(occasionSlugs: ['birthday'], interestSlugs: [$fitness->slug]);

        $ids = app(QueryDiscoveryListingProductsAction::class)->execute($context, $state)->getCollection()->pluck('id')->all();

        $this->assertSame([$this->dumbbell->id], $ids);
    }

    public function test_seo_surfaces_and_shared_query_default_stay_strict_with_everything_on(): void
    {
        $this->enableRecipientEligibility();

        $this->assertNull(RecipientEligibilityConfig::context('seo_landing'));
        $this->assertSame(
            [$this->explicitNoGender->id, $this->explicitHusbandWife->id],
            app(QueryPublishedProductsByFiltersAction::class)
                ->execute(['relationship_id' => $this->relationshipId('husband')])
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        );
    }

    public function test_unpublished_products_never_appear_via_inference(): void
    {
        $draft = Product::factory()->draft()->create(['name' => 'Draft Gift', 'slug' => 'draft-gift']);
        $draft->occasions()->attach(Occasion::query()->where('slug', 'birthday')->value('id'));

        $this->enableRecipientEligibility();

        $this->assertNotContains($draft->id, $this->husbandIds());
    }

    public function test_query_count_does_not_grow_with_catalog_size(): void
    {
        $this->enableRecipientEligibility();

        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->husbandIds();
            $this->listingTotal('husband');

            return count(DB::getQueryLog());
        };

        $small = $measure();

        for ($i = 1; $i <= 15; $i++) {
            $this->eligibilityProduct('Bulk Gift '.$i, ['occasions' => ['birthday'], 'genders' => ['unisex']]);
        }

        $this->assertSame($small, $measure());
    }

    /**
     * @return list<int>
     */
    private function husbandIds(string $sort = DiscoveryListingQueryState::SORT_RECOMMENDED): array
    {
        return $this->idsFor('husband', $sort);
    }

    /**
     * @return list<int>
     */
    private function idsFor(string $slug, string $sort = DiscoveryListingQueryState::SORT_RECOMMENDED): array
    {
        $context = DiscoveryListingContext::forRelationship(Relationship::query()->where('slug', $slug)->firstOrFail());

        return app(QueryDiscoveryListingProductsAction::class)
            ->execute($context, new DiscoveryListingQueryState(sort: $sort), 1, 20)
            ->getCollection()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function listingTotal(string $slug): int
    {
        return app(QueryDiscoveryListingProductsAction::class)->count(
            DiscoveryListingContext::forRelationship(Relationship::query()->where('slug', $slug)->firstOrFail()),
            new DiscoveryListingQueryState,
        );
    }
}
