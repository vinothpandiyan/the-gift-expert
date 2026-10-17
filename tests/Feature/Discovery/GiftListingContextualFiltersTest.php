<?php

namespace Tests\Feature\Discovery;

use App\Actions\Discovery\QueryDiscoveryListingProductsAction;
use App\Actions\Discovery\ResolveDiscoveryFilterOptionsAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Livewire\GiftListing;
use App\Models\BudgetRange;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsRecipientEligibilityCatalog;
use Tests\TestCase;

class GiftListingContextualFiltersTest extends TestCase
{
    use SeedsRecipientEligibilityCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedEligibilityTaxonomy();

        BudgetRange::query()->create([
            'name' => 'Under ₹2,000',
            'slug' => 'under-2000',
            'min_amount' => '0.00',
            'max_amount' => '2000.00',
            'currency' => 'INR',
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function genderSpecificRelationships(): array
    {
        return [
            'husband' => ['husband'],
            'boyfriend' => ['boyfriend'],
            'wife' => ['wife'],
            'girlfriend' => ['girlfriend'],
            'brother' => ['brother'],
            'mother' => ['mother'],
        ];
    }

    #[DataProvider('genderSpecificRelationships')]
    public function test_recipient_gender_is_hidden_on_gender_specific_relationship_pages(string $slug): void
    {
        $this->eligibilityProduct('Gender Tagged Gift', [
            'relationships' => [$slug],
            'occasions' => ['birthday'],
            'genders' => ['male', 'female'],
        ]);

        $html = $this->get(DiscoveryUrl::relationship($slug))->assertOk()->getContent();

        foreach (['desktop', 'mobile'] as $prefix) {
            $this->assertStringNotContainsString('id="'.$prefix.'-gender-heading"', $html, "{$prefix} {$slug}");
            $this->assertStringNotContainsString('id="'.$prefix.'-gender-male"', $html);
        }

        $this->assertStringContainsString('id="desktop-occasion-heading"', $html);
    }

    public function test_recipient_gender_is_retained_on_gender_neutral_relationship_pages(): void
    {
        foreach (['friends', 'parents', 'colleagues'] as $slug) {
            $this->eligibilityProduct('Neutral '.$slug, [
                'relationships' => [$slug],
                'occasions' => ['birthday'],
                'genders' => ['male', 'female'],
            ]);

            $html = $this->get(DiscoveryUrl::relationship($slug))->assertOk()->getContent();

            foreach (['desktop', 'mobile'] as $prefix) {
                $this->assertStringContainsString('id="'.$prefix.'-gender-heading"', $html, "{$prefix} {$slug}");
            }
        }
    }

    public function test_stale_gender_selection_is_cleared_on_gender_specific_pages(): void
    {
        $male = $this->eligibilityProduct('Husband Male Gift', [
            'relationships' => ['husband'],
            'occasions' => ['birthday'],
            'genders' => ['male'],
        ]);
        $female = $this->eligibilityProduct('Husband Female Tagged Gift', [
            'relationships' => ['husband'],
            'occasions' => ['birthday'],
            'genders' => ['female'],
        ]);

        $context = DiscoveryListingContext::forRelationship($this->relationship('husband'));

        Livewire::withQueryParams(['gender' => 'female'])
            ->test(GiftListing::class, ['context' => $context->toArray()])
            ->assertSet('gender', '')
            ->assertSee($male->name)
            ->assertSee($female->name)
            ->assertDontSee('Recipient gender');

        // A gender set after mount (e.g. a stale client) is dropped on the next render.
        Livewire::test(GiftListing::class, ['context' => $context->toArray()])
            ->set('gender', 'male')
            ->assertSet('gender', '')
            ->assertSee($male->name)
            ->assertSee($female->name);
    }

    public function test_hidden_gender_state_does_not_alter_listing_results_or_eligibility(): void
    {
        $this->enableRecipientEligibility();

        $dumbbell = $this->eligibilityProduct('Hex Dumbbell Pair', [
            'occasions' => ['birthday'],
            'genders' => ['unisex'],
            'categories' => ['sports-and-outdoors'],
        ]);
        $womenBag = $this->eligibilityProduct('Women Bag', [
            'occasions' => ['birthday'],
            'genders' => ['female'],
        ]);
        $explicit = $this->eligibilityProduct('Husband Mug', [
            'relationships' => ['husband'],
            'occasions' => ['birthday'],
        ]);

        $context = DiscoveryListingContext::forRelationship($this->relationship('husband'));
        $query = app(QueryDiscoveryListingProductsAction::class);

        $ids = fn (DiscoveryListingQueryState $state): array => collect($query->execute($context, $state, 1, 20)->items())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $baseline = $ids(new DiscoveryListingQueryState);

        $this->assertEqualsCanonicalizing([$dumbbell->id, $explicit->id], $baseline);
        $this->assertNotContains($womenBag->id, $baseline);

        foreach (['female', 'male', 'unisex'] as $genderSlug) {
            $this->assertSame($baseline, $ids(new DiscoveryListingQueryState(genderSlugs: [$genderSlug])), $genderSlug);
        }

        Livewire::withQueryParams(['gender' => 'female'])
            ->test(GiftListing::class, ['context' => $context->toArray()])
            ->assertSet('gender', '')
            ->assertSee('Hex Dumbbell Pair')
            ->assertSee('Husband Mug')
            ->assertDontSee('Women Bag');
    }

    public function test_hiding_gender_does_not_change_pivots_or_classification(): void
    {
        $product = $this->eligibilityProduct('Pivot Gift', [
            'relationships' => ['husband'],
            'occasions' => ['birthday'],
            'genders' => ['female'],
        ]);

        $before = [
            DB::table('relationship_product')->count(),
            DB::table('recipient_gender_product')->count(),
        ];

        $this->get(DiscoveryUrl::relationship('husband'))->assertOk();

        $this->assertSame($before, [
            DB::table('relationship_product')->count(),
            DB::table('recipient_gender_product')->count(),
        ]);
        $this->assertSame(['female'], $product->fresh()->recipientGenders->pluck('slug')->all());
    }

    public function test_gender_facets_filtering_and_pagination_remain_correct_on_neutral_pages(): void
    {
        config(['discovery_ranking.per_page' => 2]);

        foreach (range(1, 3) as $i) {
            $this->eligibilityProduct('Friends Male '.$i, [
                'relationships' => ['friends'],
                'occasions' => ['birthday'],
                'genders' => ['male'],
            ]);
        }
        foreach (range(1, 2) as $i) {
            $this->eligibilityProduct('Friends Female '.$i, [
                'relationships' => ['friends'],
                'occasions' => ['birthday'],
                'genders' => ['female'],
            ]);
        }

        $context = DiscoveryListingContext::forRelationship($this->relationship('friends'));
        $options = app(ResolveDiscoveryFilterOptionsAction::class)->execute($context, new DiscoveryListingQueryState);

        $this->assertSame(3, $options['gender']->firstWhere('slug', 'male')?->count);
        $this->assertSame(2, $options['gender']->firstWhere('slug', 'female')?->count);
        $this->assertSame(5, $options['occasion']->firstWhere('slug', 'birthday')?->count);

        $state = new DiscoveryListingQueryState(genderSlugs: ['male']);
        $page1 = app(QueryDiscoveryListingProductsAction::class)->execute($context, $state, 1);
        $page2 = app(QueryDiscoveryListingProductsAction::class)->execute($context, $state, 2);

        $this->assertSame(3, $page1->total());
        $this->assertCount(2, $page1->items());
        $this->assertCount(1, $page2->items());

        Livewire::withQueryParams(['gender' => 'male'])
            ->test(GiftListing::class, ['context' => $context->toArray()])
            ->assertSet('gender', 'male')
            ->assertSee('Showing 2 of 3 gift ideas');
    }

    public function test_desktop_and_mobile_filters_use_the_same_primary_order(): void
    {
        $this->eligibilityProduct('Ordered Friends Gift', [
            'relationships' => ['friends'],
            'occasions' => ['birthday'],
            'interests' => ['fitness'],
            'gift_types' => ['experience-gifts'],
            'categories' => ['home-and-living'],
            'genders' => ['unisex'],
        ]);

        $html = $this->get(DiscoveryUrl::relationship('friends'))->assertOk()->getContent();

        foreach (['desktop', 'mobile'] as $prefix) {
            $positions = [];

            foreach (['budget', 'occasion', 'interest', 'gift_type', 'category', 'gender'] as $dimension) {
                $position = strpos($html, 'id="'.$prefix.'-'.$dimension.'-heading"');
                $this->assertNotFalse($position, "{$prefix} {$dimension} heading is rendered");
                $positions[$dimension] = $position;
            }

            $sorted = $positions;
            asort($sorted);

            $this->assertSame(
                ['budget', 'occasion', 'interest', 'gift_type', 'category', 'gender'],
                array_keys($sorted),
                "{$prefix} filter order",
            );
        }
    }

    public function test_additional_filters_follow_primary_filters_on_occasion_pages(): void
    {
        $this->eligibilityProduct('Birthday Husband Gift', [
            'relationships' => ['husband'],
            'occasions' => ['birthday'],
            'interests' => ['fitness'],
            'gift_types' => ['experience-gifts'],
            'categories' => ['home-and-living'],
            'genders' => ['male'],
        ]);

        $html = $this->get(DiscoveryUrl::occasion('birthday'))->assertOk()->getContent();

        foreach (['desktop', 'mobile'] as $prefix) {
            $order = ['budget', 'interest', 'gift_type', 'category', 'relationship', 'gender'];
            $positions = [];

            foreach ($order as $dimension) {
                $position = strpos($html, 'id="'.$prefix.'-'.$dimension.'-heading"');
                $this->assertNotFalse($position, "{$prefix} {$dimension} heading is rendered");
                $positions[$dimension] = $position;
            }

            $sorted = $positions;
            asort($sorted);

            $this->assertSame($order, array_keys($sorted), "{$prefix} occasion filter order");
        }
    }

    public function test_gender_is_hidden_on_occasion_listing_when_one_gender_specific_relationship_is_selected(): void
    {
        $this->taggedForRelationships(['husband', 'wife', 'friends']);
        $context = DiscoveryListingContext::forOccasion($this->occasion('birthday'));

        foreach (['husband', 'wife'] as $slug) {
            $this->listing(['relationship' => $slug], $context)
                ->assertSet('relationship', $slug)
                ->assertDontSeeHtml('id="desktop-gender-heading"')
                ->assertDontSeeHtml('id="mobile-gender-heading"')
                ->assertDontSee('Recipient gender');
        }
    }

    public function test_gender_stays_on_occasion_listing_for_neutral_ambiguous_or_missing_relationship(): void
    {
        $this->taggedForRelationships(['husband', 'wife', 'boyfriend', 'friends']);
        $context = DiscoveryListingContext::forOccasion($this->occasion('birthday'));

        $cases = [
            'none' => [],
            'neutral' => ['relationship' => 'friends'],
            'multiple opposite genders' => ['relationship' => 'husband,wife'],
            'multiple same gender' => ['relationship' => 'boyfriend,husband'],
            'neutral plus specific' => ['relationship' => 'friends,husband'],
        ];

        foreach ($cases as $query) {
            $this->listing($query, $context)
                ->assertSeeHtml('id="desktop-gender-heading"')
                ->assertSeeHtml('id="mobile-gender-heading"');
        }
    }

    public function test_stale_gender_is_cleared_when_a_gender_specific_relationship_is_selected(): void
    {
        $this->taggedForRelationships(['husband', 'friends']);
        $context = DiscoveryListingContext::forOccasion($this->occasion('birthday'));

        $this->listing(['relationship' => 'husband', 'gender' => 'female'], $context)
            ->assertSet('relationship', 'husband')
            ->assertSet('gender', '');

        // Selecting the relationship after a gender is already chosen clears the gender.
        $this->listing(['gender' => 'female'], $context)
            ->assertSet('gender', 'female')
            ->call('setFilter', 'relationship', 'husband', true)
            ->assertSet('relationship', 'husband')
            ->assertSet('gender', '');

        // A neutral relationship keeps the gender selection.
        $this->listing(['relationship' => 'friends', 'gender' => 'female'], $context)
            ->assertSet('gender', 'female');

        // Removing the relationship brings the filter back (and it starts unselected).
        $this->listing(['relationship' => 'husband'], $context)
            ->call('removeFilter', 'relationship', 'husband')
            ->assertSet('relationship', '')
            ->assertSet('gender', '')
            ->assertSeeHtml('id="desktop-gender-heading"');
    }

    public function test_gender_is_kept_when_the_selected_relationship_is_not_valid_for_the_page(): void
    {
        // Husband is excluded from baby showers, so the relationship selection is
        // dropped by normalization and cannot make Gender redundant.
        $this->eligibilityProduct('Baby Shower Gift', [
            'occasions' => ['baby-shower'],
            'genders' => ['female'],
        ]);
        $context = DiscoveryListingContext::forOccasion($this->occasion('baby-shower'));

        $this->listing(['relationship' => 'husband', 'gender' => 'female'], $context)
            ->assertSet('relationship', '')
            ->assertSet('gender', 'female');
    }

    public function test_gender_is_hidden_on_other_surfaces_when_one_gender_specific_relationship_is_selected(): void
    {
        $this->taggedForRelationships(['husband', 'friends']);

        $adult = RecipientType::query()->where('slug', 'adult')->first()
            ?? RecipientType::query()->create(['name' => 'Adult', 'slug' => 'adult', 'is_active' => true]);
        $engineer = Profession::query()->create(['name' => 'Engineer', 'slug' => 'engineer', 'is_active' => true]);

        foreach ([
            'gift ideas' => DiscoveryListingContext::forGiftIdeas(),
            'recipient type' => DiscoveryListingContext::forRecipientType($adult),
            'profession' => DiscoveryListingContext::forProfession($engineer),
        ] as $surface => $context) {
            $options = app(ResolveDiscoveryFilterOptionsAction::class);

            $this->assertArrayNotHasKey('gender', $options->execute($context, new DiscoveryListingQueryState(relationshipSlugs: ['husband'])), $surface);
            $this->assertTrue($context->allows('gender'), $surface);
            $this->assertFalse($context->contextualizedFor(new DiscoveryListingQueryState(relationshipSlugs: ['husband']))->allows('gender'), $surface);
            $this->assertTrue($context->contextualizedFor(new DiscoveryListingQueryState(relationshipSlugs: ['friends']))->allows('gender'), $surface);
            $this->assertTrue($context->contextualizedFor(new DiscoveryListingQueryState(relationshipSlugs: ['husband', 'wife']))->allows('gender'), $surface);
        }
    }

    public function test_surfaces_without_a_relationship_filter_never_contextualize_gender(): void
    {
        $adult = RecipientType::query()->create(['name' => 'Grown Up', 'slug' => 'grown-up', 'is_active' => true]);

        // The relationship is fixed (not user-selectable), so only the fixed rule applies.
        $fixed = DiscoveryListingContext::forRelationship($this->relationship('friends'));
        $this->assertSame($fixed, $fixed->contextualizedFor(new DiscoveryListingQueryState(relationshipSlugs: ['husband'])));

        $listing = DiscoveryListingContext::forRecipientType($adult);
        $this->assertSame(
            $listing->availableDimensions,
            $listing->contextualizedFor(new DiscoveryListingQueryState)->availableDimensions,
        );
    }

    public function test_selected_relationship_hides_gender_without_changing_eligibility_or_results(): void
    {
        $dumbbell = $this->eligibilityProduct('Hex Dumbbell Pair', [
            'occasions' => ['birthday'],
            'genders' => ['unisex'],
            'categories' => ['sports-and-outdoors'],
        ]);
        $womenBag = $this->eligibilityProduct('Women Bag', [
            'occasions' => ['birthday'],
            'genders' => ['female'],
        ]);
        $explicit = $this->eligibilityProduct('Husband Mug', [
            'relationships' => ['husband'],
            'occasions' => ['birthday'],
        ]);

        $context = DiscoveryListingContext::forOccasion($this->occasion('birthday'));
        $query = app(QueryDiscoveryListingProductsAction::class);
        $ids = fn (DiscoveryListingQueryState $state): array => collect($query->execute($context, $state, 1, 20)->items())
            ->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        foreach (['strict' => false, 'inference' => true] as $mode => $inference) {
            $inference ? $this->enableRecipientEligibility() : $this->useStrictRecipientEligibility();

            $relationshipOnly = $ids(new DiscoveryListingQueryState(relationshipSlugs: ['husband']));

            $this->assertContains($explicit->id, $relationshipOnly, $mode);
            $this->assertNotContains($womenBag->id, $relationshipOnly, "{$mode}: gender-incompatible gift never leaks");
            $this->assertSame($inference, in_array($dumbbell->id, $relationshipOnly, true), $mode);

            foreach (['female', 'male', 'unisex'] as $gender) {
                $this->assertSame(
                    $relationshipOnly,
                    $ids(new DiscoveryListingQueryState(relationshipSlugs: ['husband'], genderSlugs: [$gender])),
                    "{$mode}: hidden gender {$gender} is ignored",
                );
            }

            // Multiple relationships keep Gender active: it still narrows results.
            $this->assertNotSame(
                $ids(new DiscoveryListingQueryState(relationshipSlugs: ['husband', 'wife'])),
                $ids(new DiscoveryListingQueryState(relationshipSlugs: ['husband', 'wife'], genderSlugs: ['female'])),
                $mode,
            );
        }
    }

    public function test_seo_landing_page_with_gender_specific_relationship_hides_gender_without_changing_seo(): void
    {
        $this->eligibilityProduct('Husband Birthday Gift', [
            'relationships' => ['husband'],
            'occasions' => ['birthday'],
            'genders' => ['male'],
        ]);
        $this->eligibilityProduct('Friends Birthday Gift', [
            'relationships' => ['friends'],
            'occasions' => ['birthday'],
            'genders' => ['male', 'female'],
        ]);

        $husbandPage = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-husband',
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $this->relationship('husband')->id,
            'occasion_id' => $this->occasion('birthday')->id,
            'is_indexable' => true,
        ]);
        $friendsPage = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-friends',
            'heading' => 'Birthday Gifts for Friends',
            'relationship_id' => $this->relationship('friends')->id,
            'occasion_id' => $this->occasion('birthday')->id,
            'is_indexable' => true,
        ]);

        $husbandUrl = DiscoveryUrl::seoLandingPage($husbandPage->slug);
        $husbandHtml = $this->get($husbandUrl)->assertOk()->getContent();

        $this->assertStringNotContainsString('id="desktop-gender-heading"', $husbandHtml);
        $this->assertStringNotContainsString('id="mobile-gender-heading"', $husbandHtml);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $husbandHtml);
        $this->assertStringContainsString('<link rel="canonical" href="'.DiscoveryUrl::seoLandingPage($husbandPage->slug, absolute: true).'">', $husbandHtml);

        $friendsHtml = $this->get(DiscoveryUrl::seoLandingPage($friendsPage->slug))->assertOk()->getContent();
        $this->assertStringContainsString('id="desktop-gender-heading"', $friendsHtml);
        $this->assertStringContainsString('id="mobile-gender-heading"', $friendsHtml);

        // A stale gender parameter is ignored; robots and canonical behave as for any user state.
        $this->get($husbandUrl.'?gender=female')
            ->assertOk()
            ->assertSee('Husband Birthday Gift', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<link rel="canonical" href="'.DiscoveryUrl::seoLandingPage($husbandPage->slug, absolute: true).'">', false);

        $context = DiscoveryListingContext::forSeoLandingPage($husbandPage->fresh());
        $this->assertFalse($context->allows('gender'));
        $this->assertFalse($context->allows('relationship'));
        $this->assertTrue($context->allows('interest'));
    }

    public function test_seo_landing_page_without_a_fixed_relationship_still_contextualizes_on_selection(): void
    {
        $this->eligibilityProduct('Husband Birthday Gift', [
            'relationships' => ['husband'],
            'occasions' => ['birthday'],
            'genders' => ['male'],
        ]);

        $page = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts',
            'heading' => 'Birthday Gifts',
            'occasion_id' => $this->occasion('birthday')->id,
            'is_indexable' => true,
        ]);

        $context = DiscoveryListingContext::forSeoLandingPage($page->fresh());

        $this->assertTrue($context->allows('gender'));
        $this->assertTrue($context->allows('relationship'));
        $this->assertFalse($context->contextualizedFor(new DiscoveryListingQueryState(relationshipSlugs: ['husband']))->allows('gender'));
    }

    /**
     * @param  list<string>  $relationships
     */
    private function taggedForRelationships(array $relationships): void
    {
        foreach ($relationships as $slug) {
            $this->eligibilityProduct('Tagged '.$slug.' male', [
                'relationships' => [$slug],
                'occasions' => ['birthday'],
                'genders' => ['male'],
            ]);
            $this->eligibilityProduct('Tagged '.$slug.' female', [
                'relationships' => [$slug],
                'occasions' => ['birthday'],
                'genders' => ['female'],
            ]);
        }
    }

    /**
     * @param  array<string, string>  $query
     */
    private function listing(array $query, DiscoveryListingContext $context): Testable
    {
        return Livewire::withQueryParams($query)
            ->test(GiftListing::class, ['context' => $context->toArray()]);
    }

    private function occasion(string $slug): Occasion
    {
        return Occasion::query()->where('slug', $slug)->firstOrFail();
    }

    private function relationship(string $slug): Relationship
    {
        return Relationship::query()->where('slug', $slug)->firstOrFail();
    }
}
