<?php

namespace Tests\Feature\Finder;

use App\Actions\Recommendation\GenerateRecommendationsAction;
use App\Livewire\GiftFinder;
use App\Models\BudgetRange;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\RecommendationSession;
use App\Support\DiscoveryUrl;
use App\Support\Terminology;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee(Terminology::giftRecommendations(), false);
    }

    public function test_finder_renders_expected_form_fields(): void
    {
        Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Interest::query()->create([
            'name' => 'Hiking',
            'slug' => 'hiking',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        BudgetRange::query()->create([
            'name' => 'Under 1000',
            'slug' => 'under-1000',
            'min_amount' => null,
            'max_amount' => 1000,
            'currency' => 'INR',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->get(DiscoveryUrl::finder())
            ->assertOk()
            ->assertSee('Occasion', false)
            ->assertSee('Relationship', false)
            ->assertSee('Recipient', false)
            ->assertSee('Interests', false)
            ->assertSee('Profession', false)
            ->assertSee('Gift Type', false)
            ->assertSee('Budget', false)
            ->assertSee('Birthday', false)
            ->assertSee('Hiking', false)
            ->assertSee('Under 1000', false);
    }

    public function test_valid_submission_creates_session_and_redirects_to_results(): void
    {
        $occasion = Occasion::query()->create([
            'name' => 'Anniversary',
            'slug' => 'anniversary',
            'is_active' => true,
        ]);

        GiftCatalogTestHelpers::publishedGift([
            'name' => 'Frame',
            'slug' => 'frame',
        ])->occasions()->attach($occasion);

        $component = Livewire::test(GiftFinder::class)
            ->set('occasion_id', $occasion->id)
            ->call('submit')
            ->assertHasNoErrors();

        $session = RecommendationSession::query()->first();

        $this->assertNotNull($session);
        $this->assertSame($occasion->id, $session->occasion_id);
        $this->assertDatabaseCount('recommendation_sessions', 1);

        $component->assertRedirect(DiscoveryUrl::finderResults($session->uuid));
    }

    public function test_optional_fields_can_all_be_null(): void
    {
        GiftCatalogTestHelpers::publishedGift([
            'name' => 'Any Gift',
            'slug' => 'any-gift',
        ]);

        Livewire::test(GiftFinder::class)
            ->set('occasion_id', null)
            ->set('relationship_id', null)
            ->set('recipient_type_id', null)
            ->set('profession_id', null)
            ->set('gift_type_id', null)
            ->set('budget_range_id', null)
            ->set('interest_ids', [])
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect();

        $session = RecommendationSession::query()->first();

        $this->assertNotNull($session);
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
}
