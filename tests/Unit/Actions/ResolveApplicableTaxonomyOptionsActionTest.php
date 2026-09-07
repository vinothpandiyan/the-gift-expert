<?php

namespace Tests\Unit\Actions;

use App\Actions\Taxonomy\ResolveApplicableTaxonomyOptionsAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\TaxonomyApplicabilityRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResolveApplicableTaxonomyOptionsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_exclude_hides_baby_shower_for_husband(): void
    {
        $husband = $this->relationship('Husband');
        $babyShower = $this->occasion('Baby Shower');
        $birthday = $this->occasion('Birthday');

        $this->exclude($husband, TaxonomyDimension::Relationship, $babyShower, TaxonomyDimension::Occasion);

        $ids = $this->resolve(
            DiscoveryListingContext::forRelationship($husband),
            TaxonomyDimension::Occasion,
            [$babyShower->id, $birthday->id],
        );

        $this->assertSame([$birthday->id], $ids);
    }

    public function test_exclude_is_evaluated_in_the_reverse_listing_direction(): void
    {
        $husband = $this->relationship('Husband');
        $wife = $this->relationship('Wife');
        $babyShower = $this->occasion('Baby Shower');

        $this->exclude($husband, TaxonomyDimension::Relationship, $babyShower, TaxonomyDimension::Occasion);

        $ids = $this->resolve(
            DiscoveryListingContext::forOccasion($babyShower),
            TaxonomyDimension::Relationship,
            [$husband->id, $wife->id],
        );

        $this->assertSame([$wife->id], $ids);
    }

    public function test_allow_restricted_occasion_only_keeps_listed_relationships(): void
    {
        $raksha = $this->occasion('Raksha Bandhan');
        $brother = $this->relationship('Brother');
        $sister = $this->relationship('Sister');
        $husband = $this->relationship('Husband');
        $boyfriend = $this->relationship('Boyfriend');
        $father = $this->relationship('Father');

        $this->allow($raksha, TaxonomyDimension::Occasion, $brother, TaxonomyDimension::Relationship);
        $this->allow($raksha, TaxonomyDimension::Occasion, $sister, TaxonomyDimension::Relationship);

        $fromOccasion = $this->resolve(
            DiscoveryListingContext::forOccasion($raksha),
            TaxonomyDimension::Relationship,
            [$brother->id, $sister->id, $husband->id, $boyfriend->id, $father->id],
        );

        $this->assertSame([$brother->id, $sister->id], $fromOccasion);

        $this->assertSame(
            [],
            $this->resolve(DiscoveryListingContext::forRelationship($husband), TaxonomyDimension::Occasion, [$raksha->id]),
        );
        $this->assertSame(
            [$raksha->id],
            $this->resolve(DiscoveryListingContext::forRelationship($brother), TaxonomyDimension::Occasion, [$raksha->id]),
        );
    }

    public function test_allow_restricted_value_does_not_limit_the_allowed_counterpart_elsewhere(): void
    {
        $raksha = $this->occasion('Raksha Bandhan');
        $birthday = $this->occasion('Birthday');
        $brother = $this->relationship('Brother');

        $this->allow($raksha, TaxonomyDimension::Occasion, $brother, TaxonomyDimension::Relationship);

        $ids = $this->resolve(
            DiscoveryListingContext::forRelationship($brother),
            TaxonomyDimension::Occasion,
            [$raksha->id, $birthday->id],
        );

        $this->assertSame([$raksha->id, $birthday->id], $ids);
    }

    public function test_multiple_contexts_exclude_when_any_context_excludes(): void
    {
        $husband = $this->relationship('Husband');
        $tech = $this->interest('Tech & Gadgets', 'technology');
        $babyShower = $this->occasion('Baby Shower');
        $birthday = $this->occasion('Birthday');

        $this->exclude($husband, TaxonomyDimension::Relationship, $babyShower, TaxonomyDimension::Occasion);

        $context = new DiscoveryListingContext(
            surface: 'seo_landing',
            fixedFilters: [
                'relationship_id' => $husband->id,
                'interest_ids' => [$tech->id],
            ],
            availableDimensions: ['occasion'],
            browseContext: 'test',
        );

        $ids = $this->resolve($context, TaxonomyDimension::Occasion, [$babyShower->id, $birthday->id]);

        $this->assertSame([$birthday->id], $ids);
    }

    public function test_user_filter_context_from_query_state_is_applied(): void
    {
        $husband = $this->relationship('Husband');
        $babyShower = $this->occasion('Baby Shower');
        $birthday = $this->occasion('Birthday');

        $this->exclude($husband, TaxonomyDimension::Relationship, $babyShower, TaxonomyDimension::Occasion);

        $ids = $this->resolve(
            DiscoveryListingContext::forGiftIdeas(),
            TaxonomyDimension::Occasion,
            [$babyShower->id, $birthday->id],
            new DiscoveryListingQueryState(relationshipSlugs: ['husband']),
        );

        $this->assertSame([$birthday->id], $ids);
    }

    public function test_husband_excludes_return_gifts_but_kids_do_not(): void
    {
        $husband = $this->relationship('Husband');
        $kids = $this->recipientType('Kids');
        $returnGifts = $this->giftType('Return Gifts', 'return-gifts');
        $hampers = $this->giftType('Hampers / Gift Sets', 'hampers-gift-sets');

        $this->exclude($husband, TaxonomyDimension::Relationship, $returnGifts, TaxonomyDimension::GiftType);

        $this->assertSame(
            [$hampers->id],
            $this->resolve(
                DiscoveryListingContext::forRelationship($husband),
                TaxonomyDimension::GiftType,
                [$returnGifts->id, $hampers->id],
            ),
        );

        $this->assertSame(
            [$returnGifts->id, $hampers->id],
            $this->resolve(
                DiscoveryListingContext::forRecipientType($kids),
                TaxonomyDimension::GiftType,
                [$returnGifts->id, $hampers->id],
            ),
        );
    }

    public function test_combination_without_a_rule_remains_allowed(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');

        $this->assertSame(
            [$birthday->id],
            $this->resolve(
                DiscoveryListingContext::forRelationship($husband),
                TaxonomyDimension::Occasion,
                [$birthday->id],
            ),
        );
    }

    public function test_inactive_exclude_rule_is_ignored(): void
    {
        $husband = $this->relationship('Husband');
        $babyShower = $this->occasion('Baby Shower');

        $this->exclude(
            $husband,
            TaxonomyDimension::Relationship,
            $babyShower,
            TaxonomyDimension::Occasion,
            active: false,
        );

        $this->assertSame(
            [$babyShower->id],
            $this->resolve(
                DiscoveryListingContext::forRelationship($husband),
                TaxonomyDimension::Occasion,
                [$babyShower->id],
            ),
        );
    }

    public function test_inactive_source_context_does_not_apply_its_rules(): void
    {
        $husband = $this->relationship('Husband');
        $babyShower = $this->occasion('Baby Shower');
        $birthday = $this->occasion('Birthday');

        $this->exclude($husband, TaxonomyDimension::Relationship, $babyShower, TaxonomyDimension::Occasion);
        $husband->update(['is_active' => false]);

        $ids = $this->resolve(
            DiscoveryListingContext::forRelationship($husband),
            TaxonomyDimension::Occasion,
            [$babyShower->id, $birthday->id],
        );

        $this->assertSame([$babyShower->id, $birthday->id], $ids);
    }

    public function test_inactive_candidates_are_skipped(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $inactive = $this->occasion('Festival');
        $inactive->update(['is_active' => false]);

        $this->assertSame(
            [$birthday->id],
            $this->resolve(
                DiscoveryListingContext::forRelationship($husband),
                TaxonomyDimension::Occasion,
                [$birthday->id, $inactive->id],
            ),
        );
    }

    public function test_allow_restriction_remains_when_all_allowed_targets_are_inactive(): void
    {
        $raksha = $this->occasion('Raksha Bandhan');
        $brother = $this->relationship('Brother');
        $sister = $this->relationship('Sister');
        $husband = $this->relationship('Husband');
        $father = $this->relationship('Father');

        $this->allow($raksha, TaxonomyDimension::Occasion, $brother, TaxonomyDimension::Relationship);
        $this->allow($raksha, TaxonomyDimension::Occasion, $sister, TaxonomyDimension::Relationship);
        $brother->update(['is_active' => false]);
        $sister->update(['is_active' => false]);

        $this->assertSame(
            [],
            $this->resolve(
                DiscoveryListingContext::forRelationship($husband),
                TaxonomyDimension::Occasion,
                [$raksha->id],
            ),
        );
        $this->assertSame(
            [],
            $this->resolve(
                DiscoveryListingContext::forRelationship($father),
                TaxonomyDimension::Occasion,
                [$raksha->id],
            ),
        );
        $this->assertSame(
            [],
            $this->resolve(
                DiscoveryListingContext::forOccasion($raksha),
                TaxonomyDimension::Relationship,
                [$husband->id, $brother->id, $sister->id, $father->id],
            ),
        );
    }

    public function test_allow_restriction_keeps_remaining_active_targets(): void
    {
        $raksha = $this->occasion('Raksha Bandhan');
        $brother = $this->relationship('Brother');
        $sister = $this->relationship('Sister');
        $husband = $this->relationship('Husband');

        $this->allow($raksha, TaxonomyDimension::Occasion, $brother, TaxonomyDimension::Relationship);
        $this->allow($raksha, TaxonomyDimension::Occasion, $sister, TaxonomyDimension::Relationship);
        $brother->update(['is_active' => false]);

        $this->assertSame(
            [$sister->id],
            $this->resolve(
                DiscoveryListingContext::forOccasion($raksha),
                TaxonomyDimension::Relationship,
                [$brother->id, $sister->id, $husband->id],
            ),
        );
        $this->assertSame(
            [],
            $this->resolve(
                DiscoveryListingContext::forRelationship($husband),
                TaxonomyDimension::Occasion,
                [$raksha->id],
            ),
        );
        $this->assertSame(
            [$raksha->id],
            $this->resolve(
                DiscoveryListingContext::forRelationship($sister),
                TaxonomyDimension::Occasion,
                [$raksha->id],
            ),
        );
    }

    public function test_stale_allow_targets_do_not_fail_open(): void
    {
        $raksha = $this->occasion('Raksha Bandhan');
        $brother = $this->relationship('Brother');
        $husband = $this->relationship('Husband');

        $this->allow($raksha, TaxonomyDimension::Occasion, $brother, TaxonomyDimension::Relationship);
        $brother->delete();

        $this->assertSame(
            [],
            $this->resolve(
                DiscoveryListingContext::forRelationship($husband),
                TaxonomyDimension::Occasion,
                [$raksha->id],
            ),
        );
    }

    public function test_budget_is_not_a_semantic_context(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');

        $context = new DiscoveryListingContext(
            surface: 'gift_ideas',
            fixedFilters: [
                'relationship_id' => $husband->id,
                'budget_range_id' => 99,
            ],
            availableDimensions: ['occasion'],
            browseContext: 'gift_ideas',
        );

        $this->assertSame(
            [$birthday->id],
            $this->resolve($context, TaxonomyDimension::Occasion, [$birthday->id]),
        );
    }

    public function test_resolver_query_count_does_not_grow_with_candidate_count(): void
    {
        $husband = $this->relationship('Husband');
        $this->exclude(
            $husband,
            TaxonomyDimension::Relationship,
            $this->occasion('Baby Shower'),
            TaxonomyDimension::Occasion,
        );

        $small = [];
        $large = [];

        foreach (range(1, 12) as $index) {
            $occasion = $this->occasion("Occasion {$index}");
            $large[] = $occasion->id;

            if ($index <= 2) {
                $small[] = $occasion->id;
            }
        }

        $context = DiscoveryListingContext::forRelationship($husband);
        $action = app(ResolveApplicableTaxonomyOptionsAction::class);

        $first = $this->countQueries(fn () => $action->execute($context, TaxonomyDimension::Occasion, $small));
        $second = $this->countQueries(fn () => $action->execute($context, TaxonomyDimension::Occasion, $large));

        $this->assertSame($first, $second);
        $this->assertLessThanOrEqual(8, $first);
    }

    public function test_candidate_models_are_accepted_and_order_is_preserved(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $anniversary = $this->occasion('Anniversary');
        $babyShower = $this->occasion('Baby Shower');

        $this->exclude($husband, TaxonomyDimension::Relationship, $babyShower, TaxonomyDimension::Occasion);

        $ids = $this->resolve(
            DiscoveryListingContext::forRelationship($husband),
            TaxonomyDimension::Occasion,
            [$anniversary, $babyShower, $birthday],
        );

        $this->assertSame([$anniversary->id, $birthday->id], $ids);
    }

    /**
     * @param  iterable<int|object>  $candidates
     * @return list<int>
     */
    private function resolve(
        DiscoveryListingContext $listing,
        TaxonomyDimension $dimension,
        iterable $candidates,
        ?DiscoveryListingQueryState $queryState = null,
    ): array {
        return app(ResolveApplicableTaxonomyOptionsAction::class)
            ->execute($listing, $dimension, $candidates, $queryState);
    }

    private function exclude(
        object $source,
        TaxonomyDimension $sourceDimension,
        object $target,
        TaxonomyDimension $targetDimension,
        bool $active = true,
    ): TaxonomyApplicabilityRule {
        return $this->rule($source, $sourceDimension, $target, $targetDimension, TaxonomyApplicabilityEffect::Exclude, $active);
    }

    private function allow(
        object $source,
        TaxonomyDimension $sourceDimension,
        object $target,
        TaxonomyDimension $targetDimension,
    ): TaxonomyApplicabilityRule {
        return $this->rule($source, $sourceDimension, $target, $targetDimension, TaxonomyApplicabilityEffect::Allow);
    }

    private function rule(
        object $source,
        TaxonomyDimension $sourceDimension,
        object $target,
        TaxonomyDimension $targetDimension,
        TaxonomyApplicabilityEffect $effect,
        bool $active = true,
    ): TaxonomyApplicabilityRule {
        return TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => $sourceDimension,
            'source_id' => $source->id,
            'target_dimension' => $targetDimension,
            'target_id' => $target->id,
            'effect' => $effect,
            'reason' => 'test',
            'is_active' => $active,
        ]);
    }

    private function relationship(string $name): Relationship
    {
        return Relationship::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function occasion(string $name): Occasion
    {
        return Occasion::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function recipientType(string $name): RecipientType
    {
        return RecipientType::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function giftType(string $name, string $slug): GiftType
    {
        return GiftType::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    private function interest(string $name, string $slug): Interest
    {
        return Interest::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();

        return count(DB::getQueryLog());
    }
}
