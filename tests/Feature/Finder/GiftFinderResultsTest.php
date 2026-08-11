<?php

namespace Tests\Feature\Finder;

use App\Livewire\GiftFinderResults;
use App\Models\Product;
use App\Models\RecommendationResult;
use App\Models\RecommendationSession;
use App\Support\DiscoveryUrl;
use App\Support\Terminology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Discovery\GiftCatalogTestHelpers;
use Tests\TestCase;

class GiftFinderResultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_uuid_returns_ok(): void
    {
        $session = RecommendationSession::query()->create([]);
        $gift = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Recommended Frame',
            'slug' => 'recommended-frame',
        ]);

        $this->createResult($session, $gift, rank: 1, score: 40, explanation: 'Matches Birthday.');

        $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->assertSee(Terminology::giftRecommendations(), false)
            ->assertSee('Recommended Frame', false);
    }

    public function test_results_are_displayed_in_rank_order(): void
    {
        $session = RecommendationSession::query()->create([]);

        $second = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Second Gift',
            'slug' => 'second-gift',
        ]);
        $first = GiftCatalogTestHelpers::publishedGift([
            'name' => 'First Gift',
            'slug' => 'first-gift',
        ]);

        $this->createResult($session, $second, rank: 2, score: 10, explanation: 'Lower match.');
        $this->createResult($session, $first, rank: 1, score: 50, explanation: 'Better match.');

        $html = $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->getContent();

        $this->assertTrue(
            strpos($html, 'First Gift') < strpos($html, 'Second Gift'),
            'Results should render in ascending rank order.',
        );
    }

    public function test_gift_card_relations_are_eager_loaded(): void
    {
        $session = RecommendationSession::query()->create([]);
        $gift = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Eager Gift',
            'slug' => 'eager-gift',
        ]);

        $this->createResult($session, $gift, rank: 1, score: 25, explanation: 'Featured gift.');

        $component = Livewire::test(GiftFinderResults::class, ['uuid' => $session->uuid]);
        $results = $component->instance()->results();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->relationLoaded('product'));
        $this->assertTrue($results->first()->product->relationLoaded('images'));
        $this->assertTrue($results->first()->product->relationLoaded('affiliateLinks'));
        $this->assertTrue($results->first()->product->affiliateLinks->first()->relationLoaded('merchant'));
    }

    public function test_score_breakdown_and_explanation_are_not_exposed(): void
    {
        $session = RecommendationSession::query()->create([]);
        $gift = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Quiet Gift',
            'slug' => 'quiet-gift',
        ]);

        $explanation = 'INTERNAL_EXPLANATION_TOKEN_DO_NOT_SHOW';
        $this->createResult(
            $session,
            $gift,
            rank: 1,
            score: 77.25,
            explanation: $explanation,
            breakdown: [
                'occasion_match' => 25,
                'total' => 77.25,
                'secret_breakdown_key' => 52.25,
            ],
        );

        $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->assertSee('Quiet Gift', false)
            ->assertDontSee($explanation, false)
            ->assertDontSee('77.25', false)
            ->assertDontSee('secret_breakdown_key', false)
            ->assertDontSee('occasion_match', false);
    }

    public function test_missing_uuid_returns_not_found(): void
    {
        $this->get(DiscoveryUrl::finderResults('00000000-0000-0000-0000-000000000000'))
            ->assertNotFound();
    }

    public function test_empty_recommendation_session_displays_empty_state(): void
    {
        $session = RecommendationSession::query()->create([]);

        $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->assertSee('could not find matching', false)
            ->assertSee('Start Over', false)
            ->assertSee(DiscoveryUrl::finder(), false);
    }

    public function test_start_over_points_to_finder(): void
    {
        $session = RecommendationSession::query()->create([]);

        $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->assertSee('href="'.DiscoveryUrl::finder().'"', false);
    }

    public function test_results_exclude_unpublished_products(): void
    {
        $session = RecommendationSession::query()->create([]);

        $published = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Visible Gift',
            'slug' => 'visible-gift',
        ]);

        $draft = Product::factory()->draft()->create([
            'name' => 'Hidden Draft Gift',
            'slug' => 'hidden-draft-gift',
        ]);

        $this->createResult($session, $published, rank: 1, score: 40, explanation: 'Visible.');
        $this->createResult($session, $draft, rank: 2, score: 30, explanation: 'Should stay hidden.');

        $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->assertSee('Visible Gift', false)
            ->assertDontSee('Hidden Draft Gift', false);
    }

    /**
     * @param  array<string, float|int>  $breakdown
     */
    private function createResult(
        RecommendationSession $session,
        Product $product,
        int $rank,
        float|int $score,
        string $explanation,
        array $breakdown = [],
    ): RecommendationResult {
        return RecommendationResult::query()->create([
            'recommendation_session_id' => $session->id,
            'product_id' => $product->id,
            'score' => $score,
            'rank' => $rank,
            'score_breakdown' => $breakdown !== [] ? $breakdown : ['total' => $score],
            'explanation' => $explanation,
        ]);
    }
}
