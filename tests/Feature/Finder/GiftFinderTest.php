<?php

namespace Tests\Feature\Finder;

use App\Actions\Recommendation\GenerateRecommendationsAction;
use App\Livewire\GiftFinder;
use App\Models\BudgetRange;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\RecipientType;
use App\Models\RecommendationSession;
use App\Models\Relationship;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->assertSee('Step 1 of 5', false);
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

    public function test_occasion_select_and_interest_max_and_deselect(): void
    {
        $occasion = $this->occasion('Birthday');
        $first = $this->interest('Travel', 1);
        $second = $this->interest('Coffee', 2);
        $third = $this->interest('Books', 3);
        $fourth = $this->interest('Music', 4);

        Livewire::test(GiftFinder::class)
            ->set('step', 2)
            ->call('selectOccasion', $occasion->id)
            ->call('continueStep')
            ->assertSet('step', 3)
            ->call('toggleInterest', $first->id)
            ->call('toggleInterest', $second->id)
            ->call('toggleInterest', $third->id)
            ->call('toggleInterest', $fourth->id)
            ->assertSet('interest_ids', [$first->id, $second->id, $third->id])
            ->call('toggleInterest', $second->id)
            ->assertSet('interest_ids', [$first->id, $third->id]);
    }

    public function test_optional_about_them_can_be_skipped_and_selections_persist(): void
    {
        $recipientType = RecipientType::query()->create([
            'name' => 'Adult',
            'slug' => 'adult',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Livewire::test(GiftFinder::class)
            ->set('step', 4)
            ->call('skipAbout')
            ->assertSet('step', 5)
            ->assertSet('recipient_type_id', null)
            ->set('step', 4)
            ->call('selectRecipientType', $recipientType->id)
            ->call('continueStep')
            ->assertSet('step', 5)
            ->call('back')
            ->assertSet('step', 4)
            ->assertSet('recipient_type_id', $recipientType->id)
            ->call('selectRecipientType', $recipientType->id)
            ->assertSet('recipient_type_id', null);
    }

    public function test_budget_select_and_find_gifts_creates_session(): void
    {
        $relationship = $this->relationship('Wife');
        $occasion = $this->occasion('Anniversary');
        $interest = $this->interest('Travel');
        $budget = $this->budgetRange('₹1,000–₹2,500');

        GiftCatalogTestHelpers::publishedGift([
            'name' => 'Frame',
            'slug' => 'frame',
        ])->relationships()->attach($relationship);

        $component = Livewire::test(GiftFinder::class)
            ->call('selectRelationship', $relationship->id)
            ->call('continueStep')
            ->call('selectOccasion', $occasion->id)
            ->call('continueStep')
            ->call('toggleInterest', $interest->id)
            ->call('continueStep')
            ->call('skipAbout')
            ->call('selectBudget', $budget->id)
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

    public function test_more_than_max_interests_fails_validation(): void
    {
        $max = (int) config('gift_recommendations.max_interests');
        $interestIds = [];

        for ($i = 1; $i <= $max + 1; $i++) {
            $interestIds[] = Interest::query()->create([
                'name' => "Interest {$i}",
                'slug' => "interest-{$i}",
                'is_active' => true,
                'sort_order' => $i,
            ])->id;
        }

        Livewire::test(GiftFinder::class)
            ->set('interest_ids', $interestIds)
            ->call('submit')
            ->assertHasErrors(['interest_ids']);

        $this->assertDatabaseCount('recommendation_sessions', 0);
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

    public function test_session_query_hydrates_finder_state(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $interest = $this->interest('Coffee');
        $budget = $this->budgetRange('Under ₹500');

        $session = RecommendationSession::query()->create([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
            'budget_range_id' => $budget->id,
        ]);
        $session->interests()->attach($interest->id);

        Livewire::test(GiftFinder::class, ['session' => $session->uuid])
            ->assertSet('step', 1)
            ->assertSet('relationship_id', $relationship->id)
            ->assertSet('occasion_id', $occasion->id)
            ->assertSet('budget_range_id', $budget->id)
            ->assertSet('interest_ids', [$interest->id]);

        $this->get(DiscoveryUrl::finderEdit($session->uuid))
            ->assertOk()
            ->assertSee('Husband', false);
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

    public function test_query_slugs_prefill_finder_selections_without_submitting(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $budget = $this->budgetRange('₹1,000–₹2,500');
        $budget->update(['slug' => '1000-2500']);

        Livewire::withQueryParams([
            'relationship' => 'husband',
            'occasion' => 'birthday',
            'budget' => '1000-2500',
        ])
            ->test(GiftFinder::class)
            ->assertSet('step', 1)
            ->assertSet('relationship_id', $relationship->id)
            ->assertSet('occasion_id', $occasion->id)
            ->assertSet('budget_range_id', $budget->id);

        $this->assertDatabaseCount('recommendation_sessions', 0);
    }

    public function test_invalid_query_slugs_are_ignored_and_session_wins(): void
    {
        $relationship = $this->relationship('Husband');
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
        ])
            ->test(GiftFinder::class)
            ->assertSet('relationship_id', null)
            ->assertSet('occasion_id', $occasion->id)
            ->assertSet('budget_range_id', null);

        $session = RecommendationSession::query()->create([
            'relationship_id' => $relationship->id,
        ]);

        Livewire::withQueryParams([
            'session' => $session->uuid,
            'relationship' => 'wife',
        ])
            ->test(GiftFinder::class)
            ->assertSet('relationship_id', $relationship->id);
    }

    public function test_finder_step_one_query_count_stays_bounded(): void
    {
        $this->relationship('Husband');
        $this->occasion('Birthday');
        $this->interest('Travel');
        $this->budgetRange('Under ₹500');

        $this->get(DiscoveryUrl::finder())->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(DiscoveryUrl::finder())->assertOk();

        $this->assertLessThanOrEqual(30, count(DB::getQueryLog()));
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

    private function budgetRange(string $name): BudgetRange
    {
        return BudgetRange::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'min_amount' => 1000,
            'max_amount' => 2500,
            'currency' => 'INR',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }
}
