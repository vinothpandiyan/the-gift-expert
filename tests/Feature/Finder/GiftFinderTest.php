<?php

namespace Tests\Feature\Finder;

use App\Actions\Recommendation\GenerateRecommendationsAction;
use App\Livewire\GiftFinder;
use App\Models\BudgetRange;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Profession;
use App\Models\RecipientGender;
use App\Models\RecommendationSession;
use App\Models\Relationship;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Feature\Discovery\GiftCatalogTestHelpers;
use Tests\TestCase;

class GiftFinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_finder_route_returns_ok(): void
    {
        $this->get(DiscoveryUrl::finder())
            ->assertOk()
            ->assertSee('Find a Gift', false)
            ->assertSee('Who are you buying for?', false)
            ->assertSee('Gift Finder', false)
            ->assertSee('Step 1 of 4', false);
    }

    public function test_no_query_parameters_starts_at_step_one_with_nothing_selected(): void
    {
        $this->relationship('Husband');

        Livewire::test(GiftFinder::class)
            ->assertSet('step', 1)
            ->assertSet('relationship_id', null)
            ->assertSet('occasion_id', null)
            ->assertSet('budget_range_id', null)
            ->assertSet('any_budget', false)
            ->assertSet('interest_ids', [])
            ->assertDontSee('Your picks');
    }

    public function test_initial_step_lists_active_relationships_only(): void
    {
        Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        Relationship::query()->create([
            'name' => 'Hidden Relative',
            'slug' => 'hidden-relative',
            'is_active' => false,
            'sort_order' => 2,
        ]);
        Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->get(DiscoveryUrl::finder())
            ->assertOk()
            ->assertSee('Husband', false)
            ->assertDontSee('Hidden Relative', false)
            ->assertDontSee('Birthday', false);
    }

    public function test_continue_requires_recipient_and_preserves_selection_on_back(): void
    {
        $relationship = $this->relationship('Husband');

        Livewire::test(GiftFinder::class)
            ->assertSet('step', 1)
            ->call('continueStep')
            ->assertSet('step', 1)
            ->assertSee("Choose who you're buying for to continue.")
            ->call('selectRelationship', $relationship->id)
            ->call('continueStep')
            ->assertSet('step', 2)
            ->assertSee("What's the occasion?")
            ->assertSet('relationship_id', $relationship->id)
            ->call('back')
            ->assertSet('step', 1)
            ->assertSet('relationship_id', $relationship->id)
            ->assertDontSee("Choose who you're buying for to continue.");
    }

    public function test_wizard_order_is_recipient_occasion_budget_then_optional_interests(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $budget = $this->budgetRange('Under ₹500', 'under-500');
        $this->interest('Travel');

        Livewire::test(GiftFinder::class)
            ->call('selectRelationship', $relationship->id)
            ->call('continueStep')
            ->assertSet('step', 2)
            ->call('selectOccasion', $occasion->id)
            ->call('continueStep')
            ->assertSet('step', 3)
            ->assertSee("What's your budget?")
            ->call('continueStep')
            ->assertSet('step', 3)
            ->assertSee('Choose a budget, or Any budget, to continue.')
            ->call('selectBudget', $budget->id)
            ->call('continueStep')
            ->assertSet('step', 4)
            ->assertSee('What are they into?')
            ->assertSee('Optional — choose up to 5 to personalize the results.')
            ->assertSee('Skip — show me all suitable gifts');
    }

    public function test_up_to_five_interests_can_be_selected_and_a_sixth_is_prevented(): void
    {
        $interests = collect(['Travel', 'Coffee', 'Books', 'Music', 'Gaming', 'Fitness'])
            ->map(fn (string $name, int $index) => $this->interest($name, $index + 1));

        $component = Livewire::test(GiftFinder::class);

        foreach ($interests->take(5) as $interest) {
            $component->call('toggleInterest', $interest->id);
        }

        $component
            ->assertSet('interest_ids', $interests->take(5)->pluck('id')->all())
            ->call('toggleInterest', $interests[5]->id)
            ->assertSet('interest_ids', $interests->take(5)->pluck('id')->all())
            ->assertSee('You can choose up to 5 interests.')
            ->call('toggleInterest', $interests[1]->id)
            ->assertSet('interest_ids', [
                $interests[0]->id, $interests[2]->id, $interests[3]->id, $interests[4]->id,
            ])
            ->assertDontSee('You can choose up to 5 interests.');
    }

    public function test_more_than_five_interests_fails_validation(): void
    {
        $interestIds = [];

        for ($i = 1; $i <= 6; $i++) {
            $interestIds[] = $this->interest("Interest {$i}", $i)->id;
        }

        Livewire::test(GiftFinder::class)
            ->set('interest_ids', $interestIds)
            ->call('submit')
            ->assertHasErrors(['interest_ids']);

        $this->assertDatabaseCount('recommendation_sessions', 0);
    }

    public function test_interests_are_optional_and_can_be_skipped(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $budget = $this->budgetRange('Under ₹500', 'under-500');

        GiftCatalogTestHelpers::publishedGift(['name' => 'Frame', 'slug' => 'frame', 'price_amount' => '300.00'])
            ->relationships()->attach($relationship);

        $component = Livewire::withQueryParams([
            'relationship' => 'husband',
            'occasion' => 'birthday',
            'budget' => 'under-500',
        ])
            ->test(GiftFinder::class)
            ->assertSet('step', 4)
            ->assertSet('interest_ids', [])
            ->call('submit')
            ->assertHasNoErrors();

        $session = RecommendationSession::query()->first();

        $this->assertNotNull($session);
        $this->assertSame($budget->id, $session->budget_range_id);
        $this->assertCount(0, $session->interests);
        $this->assertCount(1, $session->results);
        $component->assertRedirect(DiscoveryUrl::finderResults($session->uuid));
    }

    public function test_any_budget_is_absence_of_a_budget_not_a_taxonomy_record(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $this->budgetRange('Under ₹500', 'under-500');
        $budgetCount = BudgetRange::query()->count();

        GiftCatalogTestHelpers::publishedGift(['name' => 'Pricey', 'slug' => 'pricey', 'price_amount' => '99999.00'])
            ->relationships()->attach($relationship);

        $component = Livewire::test(GiftFinder::class)
            ->call('selectRelationship', $relationship->id)
            ->call('continueStep')
            ->call('selectOccasion', $occasion->id)
            ->call('continueStep')
            ->call('continueStep')
            ->assertSet('step', 3)
            ->call('selectAnyBudget')
            ->assertSet('budget_range_id', null)
            ->assertSet('any_budget', true)
            ->assertSet('budget', '')
            ->assertSee('Any budget')
            ->call('continueStep')
            ->assertSet('step', 4)
            ->call('submit')
            ->assertHasNoErrors();

        $session = RecommendationSession::query()->firstOrFail();

        $this->assertNull($session->budget_range_id);
        $this->assertCount(1, $session->results);
        $this->assertSame($budgetCount, BudgetRange::query()->count());
        $component->assertRedirect(DiscoveryUrl::finderResults($session->uuid));
    }

    public function test_choosing_a_budget_after_any_budget_clears_the_any_flag(): void
    {
        $budget = $this->budgetRange('Under ₹500', 'under-500');

        Livewire::test(GiftFinder::class)
            ->call('selectAnyBudget')
            ->assertSet('any_budget', true)
            ->call('selectBudget', $budget->id)
            ->assertSet('any_budget', false)
            ->assertSet('budget_range_id', $budget->id)
            ->assertSet('budget', 'under-500');
    }

    public function test_full_wizard_flow_creates_session(): void
    {
        $relationship = $this->relationship('Wife');
        $occasion = $this->occasion('Anniversary');
        $interest = $this->interest('Travel');
        $budget = $this->budgetRange('₹1,000–₹2,500', '1000-2500');

        GiftCatalogTestHelpers::publishedGift([
            'name' => 'Frame',
            'slug' => 'frame',
        ])->relationships()->attach($relationship);

        $component = Livewire::test(GiftFinder::class)
            ->call('selectRelationship', $relationship->id)
            ->call('continueStep')
            ->call('selectOccasion', $occasion->id)
            ->call('continueStep')
            ->call('selectBudget', $budget->id)
            ->call('continueStep')
            ->call('toggleInterest', $interest->id)
            ->call('submit')
            ->assertHasNoErrors();

        $session = RecommendationSession::query()->first();

        $this->assertNotNull($session);
        $this->assertSame($relationship->id, $session->relationship_id);
        $this->assertSame($occasion->id, $session->occasion_id);
        $this->assertSame($budget->id, $session->budget_range_id);
        $this->assertEqualsCanonicalizing([$interest->id], $session->interests->pluck('id')->all());
        $component->assertRedirect(DiscoveryUrl::finderResults($session->uuid));
    }

    public function test_multiple_interests_are_not_all_required_to_match(): void
    {
        $relationship = $this->relationship('Husband');
        $travel = $this->interest('Travel', 1);
        $coffee = $this->interest('Coffee', 2);
        $tech = $this->interest('Tech', 3);

        $travelOnly = GiftCatalogTestHelpers::publishedGift(['name' => 'Travel Only', 'slug' => 'travel-only']);
        $travelOnly->relationships()->attach($relationship);
        $travelOnly->interests()->attach($travel);

        $all = GiftCatalogTestHelpers::publishedGift(['name' => 'All Three', 'slug' => 'all-three']);
        $all->relationships()->attach($relationship);
        $all->interests()->attach([$travel->id, $coffee->id, $tech->id]);

        Livewire::test(GiftFinder::class)
            ->set('relationship_id', $relationship->id)
            ->call('toggleInterest', $travel->id)
            ->call('toggleInterest', $coffee->id)
            ->call('toggleInterest', $tech->id)
            ->call('submit')
            ->assertHasNoErrors();

        $session = RecommendationSession::query()->firstOrFail();

        $this->assertSame(
            [$all->id, $travelOnly->id],
            $session->results()->orderBy('rank')->pluck('product_id')->all(),
        );
    }

    public function test_finder_returns_fallback_recommendations_when_exact_matches_are_sparse(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $gaming = $this->interest('Gaming');

        $exact = GiftCatalogTestHelpers::publishedGift(['name' => 'Exact', 'slug' => 'exact']);
        $exact->relationships()->attach($relationship);
        $exact->occasions()->attach($occasion);
        $exact->interests()->attach($gaming);

        $broader = GiftCatalogTestHelpers::publishedGift(['name' => 'Broader', 'slug' => 'broader']);
        $broader->relationships()->attach($relationship);
        $broader->occasions()->attach($occasion);

        Livewire::test(GiftFinder::class)
            ->set('relationship_id', $relationship->id)
            ->set('occasion_id', $occasion->id)
            ->call('toggleInterest', $gaming->id)
            ->call('submit')
            ->assertHasNoErrors();

        $session = RecommendationSession::query()->firstOrFail();

        $this->assertSame(
            [$exact->id, $broader->id],
            $session->results()->orderBy('rank')->pluck('product_id')->all(),
        );
    }

    public function test_finder_eligibility_rules_still_apply(): void
    {
        $relationship = $this->relationship('Husband');

        $published = GiftCatalogTestHelpers::publishedGift(['name' => 'Live', 'slug' => 'live']);
        $published->relationships()->attach($relationship);

        $draft = Product::factory()->draft()->create(['name' => 'Draft', 'slug' => 'draft']);
        $draft->relationships()->attach($relationship);

        Livewire::test(GiftFinder::class)
            ->set('relationship_id', $relationship->id)
            ->call('submit');

        $session = RecommendationSession::query()->firstOrFail();

        $this->assertSame([$published->id], $session->results()->pluck('product_id')->all());
    }

    public function test_engine_still_accepts_an_all_null_payload(): void
    {
        GiftCatalogTestHelpers::publishedGift([
            'name' => 'Any Gift',
            'slug' => 'any-gift',
        ]);

        $session = app(GenerateRecommendationsAction::class)->execute([]);

        $this->assertNull($session->occasion_id);
        $this->assertNull($session->relationship_id);
        $this->assertNull($session->recipient_type_id);
        $this->assertNull($session->profession_id);
        $this->assertNull($session->gift_type_id);
        $this->assertNull($session->budget_range_id);
        $this->assertCount(0, $session->interests);
        $this->assertDatabaseCount('recommendation_sessions', 1);
        $this->assertCount(1, $session->results);
    }

    public function test_invalid_and_inactive_taxonomy_ids_fail_validation(): void
    {
        $inactive = Occasion::query()->create([
            'name' => 'Inactive Occasion',
            'slug' => 'inactive-occasion',
            'is_active' => false,
        ]);

        Livewire::test(GiftFinder::class)
            ->set('occasion_id', $inactive->id)
            ->call('submit')
            ->assertHasErrors(['occasion_id']);

        Livewire::test(GiftFinder::class)
            ->set('occasion_id', 999999)
            ->call('submit')
            ->assertHasErrors(['occasion_id']);

        $this->assertDatabaseCount('recommendation_sessions', 0);
        $this->assertDatabaseCount('recommendation_results', 0);
    }

    public function test_selecting_an_inactive_or_unknown_option_is_ignored(): void
    {
        $inactive = Relationship::query()->create([
            'name' => 'Hidden',
            'slug' => 'hidden',
            'is_active' => false,
        ]);

        Livewire::test(GiftFinder::class)
            ->call('selectRelationship', $inactive->id)
            ->assertSet('relationship_id', null)
            ->call('selectOccasion', 999999)
            ->assertSet('occasion_id', null);
    }

    public function test_validation_failure_does_not_leave_the_finder_stuck_submitting(): void
    {
        Livewire::test(GiftFinder::class)
            ->set('occasion_id', 999999)
            ->call('submit')
            ->assertHasErrors(['occasion_id'])
            ->assertSet('finding', false);
    }

    public function test_submission_invokes_generate_recommendations_action(): void
    {
        GiftCatalogTestHelpers::publishedGift([
            'name' => 'Spy Gift',
            'slug' => 'spy-gift',
        ]);

        $this->partialMock(GenerateRecommendationsAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')
                ->once()
                ->passthru();
        });

        Livewire::test(GiftFinder::class)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseCount('recommendation_sessions', 1);
    }

    public function test_double_submit_does_not_create_a_second_session(): void
    {
        GiftCatalogTestHelpers::publishedGift([
            'name' => 'Once Gift',
            'slug' => 'once-gift',
        ]);

        Livewire::test(GiftFinder::class)
            ->set('finding', true)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('recommendation_sessions', 0);

        $component = Livewire::test(GiftFinder::class)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('recommendation_sessions', 1);

        $component->call('submit');

        $this->assertDatabaseCount('recommendation_sessions', 1);
    }

    public function test_client_cannot_jump_to_interests_without_core_answers(): void
    {
        Livewire::test(GiftFinder::class)
            ->set('step', 4)
            ->assertSet('step', 1)
            ->assertSet('any_budget', false)
            ->set('step', 99)
            ->assertSet('step', 1);

        $relationship = $this->relationship('Husband');
        $this->occasion('Birthday');

        Livewire::test(GiftFinder::class)
            ->call('selectRelationship', $relationship->id)
            ->set('step', 3)
            ->assertSet('step', 2);
    }

    // ---------------------------------------------------------------------
    // Prefill from the homepage / query string
    // ---------------------------------------------------------------------

    public function test_relationship_query_is_hydrated_and_lands_on_occasion(): void
    {
        $relationship = $this->relationship('Husband');
        $this->occasion('Birthday');

        Livewire::withQueryParams(['relationship' => 'husband'])
            ->test(GiftFinder::class)
            ->assertSet('relationship_id', $relationship->id)
            ->assertSet('step', 2)
            ->assertSee("What's the occasion?");

        $this->assertDatabaseCount('recommendation_sessions', 0);
    }

    public function test_relationship_and_occasion_query_lands_on_budget(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $this->budgetRange('Under ₹500', 'under-500');

        Livewire::withQueryParams(['relationship' => 'husband', 'occasion' => 'birthday'])
            ->test(GiftFinder::class)
            ->assertSet('relationship_id', $relationship->id)
            ->assertSet('occasion_id', $occasion->id)
            ->assertSet('step', 3)
            ->assertSet('any_budget', false)
            ->assertSee("What's your budget?");
    }

    public function test_relationship_occasion_and_budget_query_lands_on_interests(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $budget = $this->budgetRange('Under ₹500', 'under-500');
        $this->interest('Travel');

        Livewire::withQueryParams([
            'relationship' => 'husband',
            'occasion' => 'birthday',
            'budget' => 'under-500',
        ])
            ->test(GiftFinder::class)
            ->assertSet('relationship_id', $relationship->id)
            ->assertSet('occasion_id', $occasion->id)
            ->assertSet('budget_range_id', $budget->id)
            ->assertSet('step', 4)
            ->assertSee('What are they into?')
            ->assertSee('Husband')
            ->assertSee('Birthday')
            ->assertSee('Under ₹500');

        $this->assertDatabaseCount('recommendation_sessions', 0);
    }

    public function test_homepage_handoff_page_renders_summary_with_change_controls(): void
    {
        $this->relationship('Husband');
        $this->occasion('Birthday');
        $this->budgetRange('Under ₹500', 'under-500');
        $this->interest('Travel');

        $this->get(DiscoveryUrl::finder().'?relationship=husband&occasion=birthday&budget=under-500')
            ->assertOk()
            ->assertSee('What are they into?', false)
            ->assertSee('Your picks', false)
            ->assertSee('Change who you&#039;re buying for: ', false)
            ->assertSee('Change the occasion: ', false)
            ->assertSee('Change the budget: ', false)
            ->assertSee('Step 4 of 4', false)
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('<link rel="canonical" href="'.DiscoveryUrl::finder(absolute: true).'">', false);
    }

    public function test_interest_slugs_in_the_query_are_hydrated_up_to_the_limit(): void
    {
        $this->relationship('Husband');
        $this->occasion('Birthday');
        $this->budgetRange('Under ₹500', 'under-500');

        $interests = collect(['Travel', 'Coffee', 'Books', 'Music', 'Gaming', 'Fitness'])
            ->map(fn (string $name, int $index) => $this->interest($name, $index + 1));

        $component = Livewire::withQueryParams([
            'relationship' => 'husband',
            'occasion' => 'birthday',
            'budget' => 'under-500',
            'interest' => 'travel,coffee,books,music,gaming,fitness,not-real',
        ])->test(GiftFinder::class);

        $this->assertCount(5, $component->get('interest_ids'));
        $component->assertSet('step', 4);
    }

    public function test_step_four_without_a_budget_in_the_url_means_any_budget(): void
    {
        $this->relationship('Husband');
        $this->occasion('Birthday');
        $this->budgetRange('Under ₹500', 'under-500');

        Livewire::withQueryParams([
            'relationship' => 'husband',
            'occasion' => 'birthday',
            'step' => '4',
        ])
            ->test(GiftFinder::class)
            ->assertSet('step', 4)
            ->assertSet('any_budget', true)
            ->assertSet('budget_range_id', null);
    }

    public function test_step_in_url_cannot_skip_ahead_of_answers(): void
    {
        $this->relationship('Husband');
        $this->occasion('Birthday');

        Livewire::withQueryParams(['relationship' => 'husband', 'step' => '3'])
            ->test(GiftFinder::class)
            ->assertSet('step', 2);

        Livewire::withQueryParams(['step' => '4'])
            ->test(GiftFinder::class)
            ->assertSet('step', 1)
            ->assertSet('any_budget', false);
    }

    public function test_step_in_url_restores_an_earlier_step_with_answers_kept(): void
    {
        $relationship = $this->relationship('Husband');
        $this->occasion('Birthday');
        $this->budgetRange('Under ₹500', 'under-500');

        Livewire::withQueryParams([
            'relationship' => 'husband',
            'occasion' => 'birthday',
            'budget' => 'under-500',
            'step' => '2',
        ])
            ->test(GiftFinder::class)
            ->assertSet('step', 2)
            ->assertSet('relationship_id', $relationship->id);
    }

    public function test_malformed_step_query_does_not_break_the_finder(): void
    {
        $this->relationship('Husband');

        Livewire::withQueryParams(['relationship' => 'husband', 'step' => 'abc'])
            ->test(GiftFinder::class)
            ->assertSet('step', 2);

        $this->get(DiscoveryUrl::finder().'?step=abc&relationship[]=x&interest[]=y')
            ->assertOk();
    }

    public function test_invalid_and_inactive_query_slugs_fail_gracefully(): void
    {
        $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        Relationship::query()->create([
            'name' => 'Wife',
            'slug' => 'wife',
            'is_active' => false,
        ]);

        Livewire::withQueryParams([
            'relationship' => 'not-real',
            'occasion' => 'birthday',
            'budget' => 'nope',
            'interest' => 'nothing',
        ])
            ->test(GiftFinder::class)
            ->assertSet('relationship_id', null)
            ->assertSet('occasion_id', $occasion->id)
            ->assertSet('budget_range_id', null)
            ->assertSet('interest_ids', [])
            ->assertSet('step', 1);

        Livewire::withQueryParams(['relationship' => 'wife'])
            ->test(GiftFinder::class)
            ->assertSet('relationship_id', null)
            ->assertSet('step', 1);

        $this->get(DiscoveryUrl::finder().'?relationship=wife&occasion=zzz&budget=zzz&interest=zzz')
            ->assertOk()
            ->assertSee('Who are you buying for?', false);
    }

    public function test_legacy_prefill_urls_with_blank_values_still_work(): void
    {
        $this->relationship('Husband');

        $this->get(DiscoveryUrl::finder().'?relationship=&occasion=&budget=')
            ->assertOk()
            ->assertSee('Who are you buying for?', false);
    }

    public function test_url_slug_state_mirrors_answers_without_meaningless_values(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $travel = $this->interest('Travel', 1);
        $coffee = $this->interest('Coffee', 2);

        Livewire::test(GiftFinder::class)
            ->call('selectRelationship', $relationship->id)
            ->assertSet('relationship', 'husband')
            ->call('selectOccasion', $occasion->id)
            ->assertSet('occasion', 'birthday')
            ->assertSet('budget', '')
            ->call('toggleInterest', $travel->id)
            ->call('toggleInterest', $coffee->id)
            ->assertSet('interest', 'coffee,travel')
            ->call('toggleInterest', $travel->id)
            ->call('toggleInterest', $coffee->id)
            ->assertSet('interest', '');
    }

    public function test_url_slug_updates_from_history_navigation_resync_answers(): void
    {
        $relationship = $this->relationship('Husband');
        $other = $this->relationship('Wife');
        $other->update(['sort_order' => 2]);

        Livewire::test(GiftFinder::class)
            ->call('selectRelationship', $relationship->id)
            ->set('relationship', 'wife')
            ->assertSet('relationship_id', $other->id)
            ->set('relationship', 'bogus')
            ->assertSet('relationship_id', null)
            ->assertSet('relationship', '');
    }

    // ---------------------------------------------------------------------
    // Editing earlier answers
    // ---------------------------------------------------------------------

    public function test_editing_an_earlier_answer_preserves_later_answers(): void
    {
        $husband = $this->relationship('Husband');
        $boyfriend = $this->relationship('Boyfriend');
        $boyfriend->update(['sort_order' => 2]);
        $occasion = $this->occasion('Birthday');
        $budget = $this->budgetRange('Under ₹500', 'under-500');
        $travel = $this->interest('Travel');

        Livewire::withQueryParams([
            'relationship' => 'husband',
            'occasion' => 'birthday',
            'budget' => 'under-500',
            'interest' => 'travel',
        ])
            ->test(GiftFinder::class)
            ->assertSet('step', 4)
            ->call('editStep', 1)
            ->assertSet('step', 1)
            ->assertSet('occasion_id', $occasion->id)
            ->assertSet('budget_range_id', $budget->id)
            ->assertSet('interest_ids', [$travel->id])
            ->call('selectRelationship', $boyfriend->id)
            ->assertSet('relationship_id', $boyfriend->id)
            ->assertSet('relationship', 'boyfriend')
            ->assertSet('step', 4)
            ->assertSet('occasion_id', $occasion->id)
            ->assertSet('budget_range_id', $budget->id)
            ->assertSet('interest_ids', [$travel->id]);

        $this->assertNotSame($husband->id, $boyfriend->id);
    }

    public function test_editing_from_the_summary_returns_to_the_next_unanswered_step(): void
    {
        $this->relationship('Husband');
        $wife = $this->relationship('Wife');
        $wife->update(['sort_order' => 2]);
        $this->occasion('Birthday');
        $this->budgetRange('Under ₹500', 'under-500');

        Livewire::withQueryParams(['relationship' => 'husband', 'occasion' => 'birthday'])
            ->test(GiftFinder::class)
            ->assertSet('step', 3)
            ->call('editStep', 1)
            ->call('selectRelationship', $wife->id)
            ->assertSet('step', 3);
    }

    public function test_edit_step_cannot_jump_past_unanswered_questions(): void
    {
        $relationship = $this->relationship('Husband');

        Livewire::test(GiftFinder::class)
            ->call('selectRelationship', $relationship->id)
            ->call('editStep', 3)
            ->assertSet('step', 1)
            ->call('editStep', 4)
            ->assertSet('step', 1);
    }

    public function test_editing_budget_to_any_budget_keeps_other_answers(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $this->budgetRange('Under ₹500', 'under-500');

        Livewire::withQueryParams([
            'relationship' => 'husband',
            'occasion' => 'birthday',
            'budget' => 'under-500',
        ])
            ->test(GiftFinder::class)
            ->call('editStep', 3)
            ->call('selectAnyBudget')
            ->assertSet('step', 4)
            ->assertSet('budget_range_id', null)
            ->assertSet('any_budget', true)
            ->assertSet('relationship_id', $relationship->id)
            ->assertSet('occasion_id', $occasion->id)
            ->assertSee('Any budget');
    }

    // ---------------------------------------------------------------------
    // Session restoration
    // ---------------------------------------------------------------------

    public function test_session_query_hydrates_finder_state_and_opens_interests(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $interest = $this->interest('Coffee');
        $budget = $this->budgetRange('Under ₹500', 'under-500');
        $gender = RecipientGender::query()->create([
            'name' => 'Male',
            'slug' => 'male',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $session = RecommendationSession::query()->create([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
            'budget_range_id' => $budget->id,
            'recipient_gender_id' => $gender->id,
        ]);
        $session->interests()->attach($interest->id);

        Livewire::test(GiftFinder::class, ['session' => $session->uuid])
            ->assertSet('step', 4)
            ->assertSet('relationship_id', $relationship->id)
            ->assertSet('occasion_id', $occasion->id)
            ->assertSet('budget_range_id', $budget->id)
            ->assertSet('recipient_gender_id', $gender->id)
            ->assertSet('interest_ids', [$interest->id]);

        $this->get(DiscoveryUrl::finderEdit($session->uuid))
            ->assertOk()
            ->assertSee('Husband', false)
            ->assertSee('What are they into?', false);
    }

    public function test_session_with_no_budget_restores_as_any_budget(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');

        $session = RecommendationSession::query()->create([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
        ]);

        Livewire::test(GiftFinder::class, ['session' => $session->uuid])
            ->assertSet('step', 4)
            ->assertSet('any_budget', true)
            ->assertSet('budget_range_id', null)
            ->assertSee('Any budget');
    }

    public function test_legacy_session_answers_are_kept_visible_and_removable(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $profession = Profession::query()->create([
            'name' => 'Engineer',
            'slug' => 'engineer',
            'is_active' => true,
        ]);

        $session = RecommendationSession::query()->create([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
            'profession_id' => $profession->id,
        ]);

        Livewire::test(GiftFinder::class, ['session' => $session->uuid])
            ->assertSet('profession_id', $profession->id)
            ->assertSee('Engineer')
            ->call('clearAnswer', 'profession_id')
            ->assertSet('profession_id', null)
            ->call('clearAnswer', 'relationship_id')
            ->assertSet('relationship_id', $relationship->id);
    }

    public function test_clearing_restored_interests_survives_a_refresh(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $budget = $this->budgetRange('Under ₹500', 'under-500');
        $travel = $this->interest('Travel', 1);
        $coffee = $this->interest('Coffee', 2);

        // 1. saved session contains interests
        $session = RecommendationSession::query()->create([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
            'budget_range_id' => $budget->id,
        ]);
        $session->interests()->attach([$travel->id, $coffee->id]);

        // 2. session is restored (the legacy link is consumed, not kept as state)
        $component = Livewire::test(GiftFinder::class, ['session' => $session->uuid])
            ->assertSet('interest_ids', [$travel->id, $coffee->id])
            ->assertSet('session', '')
            ->assertSet('interest', 'coffee,travel');

        // 3. all interests are cleared
        $component
            ->call('toggleInterest', $travel->id)
            ->call('toggleInterest', $coffee->id)
            ->assertSet('interest_ids', [])
            // 4. the URL-bound state now represents zero interests
            ->assertSet('interest', '')
            ->assertSet('session', '');

        $url = [
            'relationship' => $component->get('relationship'),
            'occasion' => $component->get('occasion'),
            'budget' => $component->get('budget'),
            'interest' => $component->get('interest'),
            'step' => (string) $component->get('step'),
        ];

        // 5. remount exactly as a refresh would (the URL no longer carries ?session=)
        // 6. interests remain empty
        Livewire::withQueryParams($url)
            ->test(GiftFinder::class)
            ->assertSet('interest_ids', [])
            ->assertSet('step', 4)
            ->assertSet('budget_range_id', $budget->id);

        // Even if a stale ?session= is still present beside wizard state, the URL wins.
        Livewire::withQueryParams($url + ['session' => $session->uuid])
            ->test(GiftFinder::class)
            ->assertSet('interest_ids', [])
            ->assertSet('step', 4);
    }

    public function test_untouched_legacy_session_link_still_restores_everything(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $travel = $this->interest('Travel');

        $session = RecommendationSession::query()->create([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
        ]);
        $session->interests()->attach($travel->id);

        Livewire::withQueryParams(['session' => $session->uuid])
            ->test(GiftFinder::class)
            ->assertSet('relationship_id', $relationship->id)
            ->assertSet('interest_ids', [$travel->id])
            ->assertSet('step', 4)
            ->assertSet('session', '');
    }

    public function test_any_budget_chosen_after_restoring_a_budgeted_session_survives_a_refresh(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $budget = $this->budgetRange('Under ₹500', 'under-500');

        $session = RecommendationSession::query()->create([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
            'budget_range_id' => $budget->id,
        ]);

        $component = Livewire::test(GiftFinder::class, ['session' => $session->uuid])
            ->assertSet('budget', 'under-500')
            ->call('editStep', 3)
            ->call('selectAnyBudget')
            ->assertSet('budget', '')
            ->assertSet('step', 4);

        Livewire::withQueryParams([
            'relationship' => $component->get('relationship'),
            'occasion' => $component->get('occasion'),
            'step' => (string) $component->get('step'),
            'session' => $session->uuid,
        ])
            ->test(GiftFinder::class)
            ->assertSet('budget_range_id', null)
            ->assertSet('any_budget', true)
            ->assertSet('step', 4);
    }

    public function test_history_pop_applying_step_before_answers_does_not_lose_the_step(): void
    {
        $relationship = $this->relationship('Husband');
        $this->occasion('Birthday');

        // Browser Forward restores `step` first, then the answer slugs, in one batch.
        Livewire::test(GiftFinder::class)
            ->update(updates: ['step' => 2, 'relationship' => 'husband'])
            ->assertSet('relationship_id', $relationship->id)
            ->assertSet('step', 2);
    }

    public function test_only_step_pushes_history_while_answers_and_session_replace_the_entry(): void
    {
        $this->assertTrue($this->pushesHistory('step'), 'step must push so Back/Forward follow wizard navigation');

        foreach (GiftFinder::URL_ANSWER_PROPERTIES as $property) {
            $this->assertFalse($this->pushesHistory($property), "{$property} must replace the current entry");
        }

        $this->assertFalse($this->pushesHistory('session'));
    }

    // ---------------------------------------------------------------------
    // Any budget in the URL
    // ---------------------------------------------------------------------

    public function test_any_budget_survives_browser_back_followed_by_refresh(): void
    {
        $this->relationship('Husband');
        $this->occasion('Birthday');
        $this->budgetRange('Under ₹500', 'under-500');
        $this->interest('Travel');

        // 1. choose Any budget
        $component = $this->walkToBudget()
            ->call('selectAnyBudget')
            ->assertSet('any_budget', true)
            ->assertSet('budget', '')
            ->assertSet('budget_any', '1');

        $budgetEntry = $this->finderQuery($component);
        $this->assertSame(
            ['relationship' => 'husband', 'occasion' => 'birthday', 'budget_any' => '1', 'step' => '3'],
            $budgetEntry,
        );

        // 2. advance to Interests
        $component->call('continueStep')->assertSet('step', 4)->assertSet('any_budget', true);
        $this->assertSame('1', $this->finderQuery($component)['budget_any']);

        // 3. browser Back to the Budget entry
        $this->browserPopTo($component, $budgetEntry)
            ->assertSet('step', 3)
            ->assertSet('any_budget', true);
        $this->assertAnyBudgetCardSelected($component);

        // 4. refresh from that URL, 5. Any budget is still selected
        $refreshed = Livewire::withQueryParams($budgetEntry)
            ->test(GiftFinder::class)
            ->assertSet('step', 3)
            ->assertSet('any_budget', true)
            ->assertSet('budget_range_id', null)
            ->assertSet('budget_any', '1')
            ->assertSee('Any budget');
        $this->assertAnyBudgetCardSelected($refreshed);
        $this->assertSame($budgetEntry, $this->finderQuery($refreshed));
    }

    public function test_unanswered_budget_is_distinguishable_from_any_budget_in_the_url(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $budget = $this->budgetRange('Under ₹500', 'under-500');
        $this->interest('Travel');

        $unanswered = $this->walkToBudget();
        $this->assertSame(
            ['relationship' => 'husband', 'occasion' => 'birthday', 'step' => '3'],
            $this->finderQuery($unanswered),
        );

        Livewire::withQueryParams($this->finderQuery($unanswered))
            ->test(GiftFinder::class)
            ->assertSet('step', 3)
            ->assertSet('any_budget', false)
            ->assertSet('budget_range_id', null)
            ->assertSet('budget_any', '')
            ->assertSet('relationship_id', $relationship->id)
            ->assertSet('occasion_id', $occasion->id);

        // A real budget is written as its slug, never as the Any budget flag.
        $real = $this->finderQuery($this->walkToBudget()->call('selectBudget', $budget->id));
        $this->assertSame('under-500', $real['budget']);
        $this->assertArrayNotHasKey('budget_any', $real);

        // If both appear, the real budget wins and the flag is dropped.
        Livewire::withQueryParams($real + ['budget_any' => '1'])
            ->test(GiftFinder::class)
            ->assertSet('budget_range_id', $budget->id)
            ->assertSet('any_budget', false)
            ->assertSet('budget_any', '');

        // The flag only means "1"; anything else is not an answer.
        Livewire::withQueryParams(['relationship' => 'husband', 'occasion' => 'birthday', 'budget_any' => 'yes'])
            ->test(GiftFinder::class)
            ->assertSet('step', 3)
            ->assertSet('any_budget', false);
    }

    public function test_any_budget_never_creates_a_budget_record_or_slug(): void
    {
        $this->relationship('Husband');
        $this->occasion('Birthday');
        $this->budgetRange('Under ₹500', 'under-500');
        $count = BudgetRange::query()->count();

        $component = $this->walkToBudget()->call('selectAnyBudget')->call('continueStep');

        $this->assertSame('', $component->get('budget'));
        $this->assertSame($count, BudgetRange::query()->count());
    }

    public function test_browser_back_from_a_real_budget_entry_restores_the_budget_slug(): void
    {
        $budget = $this->budgetRange('Under ₹500', 'under-500');
        $this->relationship('Husband');
        $this->occasion('Birthday');
        $this->interest('Travel');

        $component = $this->walkToBudget()->call('selectBudget', $budget->id);
        $budgetEntry = $this->finderQuery($component);
        $component->call('continueStep');

        $this->browserPopTo($component, $budgetEntry)
            ->assertSet('step', 3)
            ->assertSet('budget_range_id', $budget->id)
            ->assertSet('any_budget', false)
            ->assertSet('budget_any', '');
    }

    public function test_history_pop_between_any_budget_and_a_real_budget_is_order_independent(): void
    {
        $budget = $this->budgetRange('Under ₹500', 'under-500');
        $this->relationship('Husband');
        $this->occasion('Birthday');

        $base = ['step' => 3, 'relationship' => 'husband', 'occasion' => 'birthday'];

        foreach ([
            ['budget' => '', 'budget_any' => '1'],
            ['budget_any' => '1', 'budget' => ''],
        ] as $anyBudget) {
            Livewire::test(GiftFinder::class)
                ->update(updates: $base + ['budget' => 'under-500', 'budget_any' => ''])
                ->assertSet('budget_range_id', $budget->id)
                ->assertSet('any_budget', false)
                ->update(updates: $base + $anyBudget)
                ->assertSet('budget_range_id', null)
                ->assertSet('any_budget', true)
                ->assertSet('budget', '')
                ->assertSet('budget_any', '1')
                ->update(updates: $base + ['budget' => '', 'budget_any' => ''])
                ->assertSet('any_budget', false)
                ->assertSet('step', 3);
        }

        foreach ([
            ['budget' => 'under-500', 'budget_any' => ''],
            ['budget_any' => '', 'budget' => 'under-500'],
        ] as $real) {
            Livewire::test(GiftFinder::class)
                ->update(updates: $base + ['budget' => '', 'budget_any' => '1'])
                ->assertSet('any_budget', true)
                ->update(updates: $base + $real)
                ->assertSet('budget_range_id', $budget->id)
                ->assertSet('any_budget', false)
                ->assertSet('budget_any', '');
        }
    }

    // ---------------------------------------------------------------------
    // History semantics (Livewire pushes one entry per changed push-mode property per commit)
    // ---------------------------------------------------------------------

    public function test_answering_and_toggling_interests_add_no_history_entries(): void
    {
        $husband = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $budget = $this->budgetRange('Under ₹500', 'under-500');
        $travel = $this->interest('Travel', 1);
        $coffee = $this->interest('Coffee', 2);
        $books = $this->interest('Books', 3);

        $component = Livewire::test(GiftFinder::class);

        $this->assertSame(0, $this->pushes($component, fn () => $component->call('selectRelationship', $husband->id)));
        $this->assertSame(1, $this->pushes($component, fn () => $component->call('continueStep')));
        $this->assertSame(0, $this->pushes($component, fn () => $component->call('selectOccasion', $occasion->id)));
        $this->assertSame(1, $this->pushes($component, fn () => $component->call('continueStep')));
        $this->assertSame(0, $this->pushes($component, fn () => $component->call('selectBudget', $budget->id)));
        $this->assertSame(1, $this->pushes($component, fn () => $component->call('continueStep')));

        foreach ([$travel, $coffee, $books] as $interest) {
            $this->assertSame(0, $this->pushes($component, fn () => $component->call('toggleInterest', $interest->id)));
        }

        $this->assertSame(0, $this->pushes($component, fn () => $component->call('toggleInterest', $coffee->id)));
        $component->assertSet('interest', 'books,travel');
    }

    public function test_back_from_interests_is_one_entry_back_to_budget_and_forward_restores_interests(): void
    {
        $this->relationship('Husband');
        $this->occasion('Birthday');
        $budget = $this->budgetRange('Under ₹500', 'under-500');
        $travel = $this->interest('Travel', 1);
        $coffee = $this->interest('Coffee', 2);

        $component = $this->walkToBudget()->call('selectBudget', $budget->id);
        $budgetEntry = $this->finderQuery($component);

        $component->call('continueStep')
            ->call('toggleInterest', $travel->id)
            ->call('toggleInterest', $coffee->id);
        $interestsEntry = $this->finderQuery($component);

        // Entries: [..., Budget, Interests]. Interest toggles replaced the Interests entry.
        $this->assertSame('4', $interestsEntry['step']);
        $this->assertSame('coffee,travel', $interestsEntry['interest']);
        $this->assertArrayNotHasKey('interest', $budgetEntry);

        // One Back press.
        $this->browserPopTo($component, $budgetEntry)
            ->assertSet('step', 3)
            ->assertSet('budget_range_id', $budget->id)
            ->assertSet('interest_ids', []);

        // One Forward press.
        $this->browserPopTo($component, $interestsEntry)
            ->assertSet('step', 4)
            ->assertSet('interest_ids', [$travel->id, $coffee->id]);

        // Each entry survives a refresh on its own.
        Livewire::withQueryParams($budgetEntry)->test(GiftFinder::class)
            ->assertSet('step', 3)->assertSet('interest_ids', []);
        Livewire::withQueryParams($interestsEntry)->test(GiftFinder::class)
            ->assertSet('step', 4)->assertSet('interest_ids', [$travel->id, $coffee->id]);
    }

    public function test_editing_an_earlier_answer_is_one_entry_to_the_edit_view_and_one_back_to_where_you_were(): void
    {
        $husband = $this->relationship('Husband');
        $wife = $this->relationship('Wife');
        $wife->update(['sort_order' => 2]);

        $this->occasion('Birthday');
        $this->budgetRange('Under ₹500', 'under-500');

        $component = Livewire::withQueryParams([
            'relationship' => 'husband',
            'occasion' => 'birthday',
            'budget' => 'under-500',
            'step' => '4',
        ])->test(GiftFinder::class)->assertSet('step', 4);

        // Summary chip → edit view of step 1 is one entry; choosing a value there is none.
        $this->assertSame(1, $this->pushes($component, fn () => $component->call('editStep', 1)));
        $editEntry = $this->finderQuery($component);
        $this->assertSame(['relationship' => 'husband', 'occasion' => 'birthday', 'budget' => 'under-500'], $editEntry);

        // Choosing the new answer returns to Interests: one more entry (step 1 → 4).
        $this->assertSame(1, $this->pushes($component, fn () => $component->call('selectRelationship', $wife->id)));
        $component->assertSet('step', 4)->assertSet('relationship_id', $wife->id);

        // Back lands on the edit view in one press, with the answer as it was there.
        $this->browserPopTo($component, $editEntry)
            ->assertSet('step', 1)
            ->assertSet('relationship_id', $husband->id)
            ->assertSet('budget_range_id', BudgetRange::query()->value('id'));
    }

    public function test_homepage_prefilled_landing_adds_no_history_entry_and_interest_toggles_replace(): void
    {
        $this->relationship('Husband');
        $this->occasion('Birthday');
        $this->budgetRange('Under ₹500', 'under-500');
        $travel = $this->interest('Travel');

        // Mounting on Interests is a replace of the landing entry (the URL gains `step=4`); it
        // is not a push, so Back leaves the Finder to the page the visitor came from.
        $component = Livewire::withQueryParams([
            'relationship' => 'husband',
            'occasion' => 'birthday',
            'budget' => 'under-500',
        ])->test(GiftFinder::class)->assertSet('step', 4);

        $this->assertSame(0, $this->pushes($component, fn () => $component->call('toggleInterest', $travel->id)));
        $this->assertSame(1, $this->pushes($component, fn () => $component->call('back')));
    }

    public function test_the_view_reapplies_url_answers_on_browser_history_navigation(): void
    {
        $this->get(DiscoveryUrl::finder())
            ->assertOk()
            ->assertSee('x-on:popstate.window', false)
            ->assertSee('$wire.set(property', false)
            ->assertSee('budget_any', false);
    }

    // ---------------------------------------------------------------------
    // Legacy gender: deliberately retained in the URL until the user removes it
    // ---------------------------------------------------------------------

    public function test_legacy_gender_is_kept_in_the_url_and_survives_a_refresh(): void
    {
        $session = $this->legacySessionWithGender($gender, $relationship, $occasion);
        $this->interest('Travel');

        $component = Livewire::test(GiftFinder::class, ['session' => $session->uuid])
            ->assertSet('step', 4)
            ->assertSet('recipient_gender_id', $gender->id)
            ->assertSet('gender', 'male')
            ->assertSee('Remove gender')
            ->assertSee('Male');

        $query = $this->finderQuery($component);
        $this->assertSame('male', $query['gender']);

        // Refresh: the legacy link is gone, the URL alone owns the state, and gender is still applied.
        $refreshed = Livewire::withQueryParams($query)
            ->test(GiftFinder::class)
            ->assertSet('recipient_gender_id', $gender->id)
            ->assertSet('relationship_id', $relationship->id)
            ->assertSet('occasion_id', $occasion->id)
            ->assertSet('step', 4)
            ->assertSee('Male');

        $refreshed->call('submit')->assertHasNoErrors();

        $this->assertSame($gender->id, RecommendationSession::query()->latest('id')->firstOrFail()->recipient_gender_id);
    }

    public function test_removing_legacy_gender_removes_it_from_the_url_for_good(): void
    {
        $session = $this->legacySessionWithGender($gender);

        $component = Livewire::test(GiftFinder::class, ['session' => $session->uuid])
            ->assertSet('gender', 'male')
            ->call('clearAnswer', 'recipient_gender_id')
            ->assertSet('recipient_gender_id', null)
            ->assertSet('gender', '');

        $query = $this->finderQuery($component);
        $this->assertArrayNotHasKey('gender', $query);

        // Even beside a stale legacy link, an explicit `step` means the URL owns the state.
        Livewire::withQueryParams($query + ['session' => $session->uuid])
            ->test(GiftFinder::class)
            ->assertSet('recipient_gender_id', null)
            ->assertSet('gender', '');

        $this->browserPopTo($component, $query + ['gender' => 'male'])
            ->assertSet('recipient_gender_id', $gender->id);

        $this->browserPopTo($component, $query)
            ->assertSet('recipient_gender_id', null)
            ->assertSet('gender', '');
    }

    public function test_gender_is_not_a_wizard_step_and_only_male_or_female_are_accepted_from_the_url(): void
    {
        $this->relationship('Husband');
        $this->occasion('Birthday');
        RecipientGender::query()->create(['name' => 'Male', 'slug' => 'male', 'is_active' => true, 'sort_order' => 1]);
        RecipientGender::query()->create(['name' => 'Unisex', 'slug' => 'unisex', 'is_active' => true, 'sort_order' => 3]);
        RecipientGender::query()->create(['name' => 'Female', 'slug' => 'female', 'is_active' => false, 'sort_order' => 2]);

        // Gender alone neither answers nor skips a step.
        Livewire::withQueryParams(['gender' => 'male'])
            ->test(GiftFinder::class)
            ->assertSet('step', 1)
            ->assertSee('Who are you buying for?');

        foreach (['unisex', 'female', 'bogus'] as $slug) {
            Livewire::withQueryParams(['relationship' => 'husband', 'occasion' => 'birthday', 'gender' => $slug])
                ->test(GiftFinder::class)
                ->assertSet('recipient_gender_id', null)
                ->assertSet('gender', '');
        }
    }

    public function test_unknown_session_query_is_ignored(): void
    {
        Livewire::test(GiftFinder::class, ['session' => '00000000-0000-0000-0000-000000000000'])
            ->assertSet('relationship_id', null)
            ->assertSet('step', 1);

        $this->get(DiscoveryUrl::finder().'?session=00000000-0000-0000-0000-000000000000')
            ->assertOk()
            ->assertSee('Who are you buying for?', false);
    }

    public function test_valid_query_slugs_override_session_but_invalid_ones_do_not(): void
    {
        $husband = $this->relationship('Husband');
        $wife = $this->relationship('Wife');
        $wife->update(['sort_order' => 2]);
        Relationship::query()->create([
            'name' => 'Cousin',
            'slug' => 'cousin',
            'is_active' => false,
        ]);

        $session = RecommendationSession::query()->create([
            'relationship_id' => $husband->id,
        ]);

        Livewire::withQueryParams(['session' => $session->uuid, 'relationship' => 'cousin'])
            ->test(GiftFinder::class)
            ->assertSet('relationship_id', $husband->id);

        Livewire::withQueryParams(['session' => $session->uuid, 'relationship' => 'wife'])
            ->test(GiftFinder::class)
            ->assertSet('relationship_id', $wife->id);
    }

    public function test_finder_step_one_query_count_stays_bounded(): void
    {
        $this->relationship('Husband');
        $this->occasion('Birthday');
        $this->interest('Travel');
        $this->budgetRange('Under ₹500', 'under-500');

        $this->get(DiscoveryUrl::finder())->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(DiscoveryUrl::finder())->assertOk();

        $this->assertLessThanOrEqual(30, count(DB::getQueryLog()));
    }

    private function pushesHistory(string $property): bool
    {
        $attributes = (new \ReflectionProperty(GiftFinder::class, $property))->getAttributes(Url::class);
        $this->assertCount(1, $attributes, "{$property} must be URL-bound");

        return $attributes[0]->newInstance()->history;
    }

    /**
     * Livewire pushes one history entry per push-mode URL property whose value changed in a commit.
     */
    private function pushes(Testable $component, \Closure $action): int
    {
        $pushing = array_values(array_filter(
            ['step', ...GiftFinder::URL_ANSWER_PROPERTIES, 'session'],
            fn (string $property) => $this->pushesHistory($property),
        ));

        $before = array_map(fn (string $property) => $component->get($property), $pushing);
        $action();
        $after = array_map(fn (string $property) => $component->get($property), $pushing);

        return count(array_filter(array_keys($pushing), fn (int $index) => $before[$index] !== $after[$index]));
    }

    /**
     * The query string Livewire leaves in the URL: empty answers and `step=1` are omitted.
     *
     * @return array<string, string>
     */
    private function finderQuery(Testable $component): array
    {
        $query = [];

        foreach (GiftFinder::URL_ANSWER_PROPERTIES as $property) {
            $value = (string) $component->get($property);

            if ($value !== '') {
                $query[$property] = $value;
            }
        }

        if ((int) $component->get('step') !== 1) {
            $query['step'] = (string) $component->get('step');
        }

        return $query;
    }

    /**
     * Browser Back/Forward: Livewire restores `step`, and the view re-applies every
     * URL answer property from the restored URL, all in one update batch.
     *
     * @param  array<string, string>  $query
     */
    private function browserPopTo(Testable $component, array $query): Testable
    {
        $updates = ['step' => (int) ($query['step'] ?? 1)];

        foreach (GiftFinder::URL_ANSWER_PROPERTIES as $property) {
            $updates[$property] = $query[$property] ?? '';
        }

        return $component->update(updates: $updates);
    }

    private function walkToBudget(): Testable
    {
        return Livewire::test(GiftFinder::class)
            ->call('selectRelationship', Relationship::query()->value('id'))
            ->call('continueStep')
            ->call('selectOccasion', Occasion::query()->value('id'))
            ->call('continueStep')
            ->assertSet('step', 3);
    }

    private function assertAnyBudgetCardSelected(Testable $component): void
    {
        $this->assertSame(1, substr_count($component->html(), 'aria-checked="true"'));
        $this->assertMatchesRegularExpression(
            '/aria-checked="true"(?:(?!<\/button>).)*Any budget/s',
            $component->html(),
        );
    }

    private function legacySessionWithGender(
        ?RecipientGender &$gender = null,
        ?Relationship &$relationship = null,
        ?Occasion &$occasion = null,
    ): RecommendationSession {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $gender = RecipientGender::query()->create([
            'name' => 'Male',
            'slug' => 'male',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return RecommendationSession::query()->create([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
            'recipient_gender_id' => $gender->id,
        ]);
    }

    private function relationship(string $name): Relationship
    {
        return Relationship::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function occasion(string $name): Occasion
    {
        return Occasion::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function interest(string $name, int $sortOrder = 1): Interest
    {
        return Interest::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);
    }

    private function budgetRange(string $name, string $slug): BudgetRange
    {
        return BudgetRange::query()->create([
            'name' => $name,
            'slug' => $slug,
            'min_amount' => 0,
            'max_amount' => 500,
            'currency' => 'INR',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }
}
