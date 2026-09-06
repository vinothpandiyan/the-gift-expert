<?php

namespace Tests\Feature\Finder;

use App\Enums\AffiliateLinkStatus;
use App\Livewire\GiftFinderResults;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\RecommendationResult;
use App\Models\RecommendationSession;
use App\Models\Relationship;
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
            ->assertSee('Recommended Frame', false)
            ->assertSee('We found 1 gift idea', false);
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

    public function test_session_summary_uses_persisted_preferences(): void
    {
        $relationship = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);
        $occasion = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);

        $session = RecommendationSession::query()->create([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
        ]);

        $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->assertSee('Husband', false)
            ->assertSee('Birthday', false)
            ->assertSee('Edit preferences', false)
            ->assertSee('href="'.DiscoveryUrl::finderEdit($session->uuid).'"', false);
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
        $this->assertTrue($results->first()->product->relationLoaded('categories'));
        $this->assertTrue($results->first()->product->affiliateLinks->first()->relationLoaded('merchant'));
    }

    public function test_explanation_is_shown_and_score_breakdown_is_not(): void
    {
        $session = RecommendationSession::query()->create([]);
        $gift = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Quiet Gift',
            'slug' => 'quiet-gift',
        ]);

        $explanation = 'Matches Birthday, and interests in Travel.';
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
            ->assertSee($explanation, false)
            ->assertDontSee('77.25', false)
            ->assertDontSee('secret_breakdown_key', false)
            ->assertDontSee('occasion_match', false);
    }

    public function test_great_match_uses_display_index_and_score_threshold(): void
    {
        $session = RecommendationSession::query()->create([]);
        $featuredBoost = (float) config('gift_recommendations.weights.featured_boost');

        $names = ['Match One', 'Match Two', 'Match Three', 'Match Four'];

        foreach ($names as $index => $name) {
            $gift = GiftCatalogTestHelpers::publishedGift([
                'name' => $name,
                'slug' => str($name)->slug()->toString(),
            ]);
            $this->createResult(
                $session,
                $gift,
                rank: $index + 1,
                score: 40 - $index,
                explanation: 'Matches Husband.',
            );
        }

        $html = $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->getContent();

        $this->assertSame(3, substr_count($html, 'Great Match'));
        $this->assertGreaterThan($featuredBoost, 40 - 2);

        $weakSession = RecommendationSession::query()->create([]);
        $weakGift = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Featured Only Gift',
            'slug' => 'featured-only-gift',
        ]);
        $this->createResult($weakSession, $weakGift, rank: 1, score: $featuredBoost, explanation: 'featured gift.');

        $this->get(DiscoveryUrl::finderResults($weakSession->uuid))
            ->assertOk()
            ->assertSee('Featured Only Gift', false)
            ->assertDontSee('Great Match', false);
    }

    public function test_product_link_goes_to_gift_detail_not_outbound(): void
    {
        $session = RecommendationSession::query()->create([]);
        $gift = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Linked Gift',
            'slug' => 'linked-gift',
        ]);
        $this->createResult($session, $gift, rank: 1, score: 40, explanation: 'Matches Birthday.');

        $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->assertSee('href="'.DiscoveryUrl::gift($gift->slug).'"', false)
            ->assertDontSee('context=', false)
            ->assertDontSee('/out/', false);
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
            ->assertSee("We couldn't find a strong match yet")
            ->assertSee('Edit preferences', false)
            ->assertSee('Start over', false)
            ->assertSee('Browse gift ideas', false)
            ->assertSee(DiscoveryUrl::finder(), false)
            ->assertSee(DiscoveryUrl::finderEdit($session->uuid), false)
            ->assertSee(DiscoveryUrl::giftIdeas(), false);
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

    public function test_results_exclude_products_without_an_active_affiliate_link(): void
    {
        $session = RecommendationSession::query()->create([]);
        $visible = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Affiliate Gift',
            'slug' => 'affiliate-gift',
        ]);
        $this->createResult($session, $visible, rank: 1, score: 40, explanation: 'Visible.');

        $orphan = Product::factory()->published()->create([
            'name' => 'Orphan Gift',
            'slug' => 'orphan-gift',
        ]);
        $this->createResult($session, $orphan, rank: 2, score: 30, explanation: 'No affiliate.');

        $inactive = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Inactive Link Gift',
            'slug' => 'inactive-link-gift',
        ]);
        $inactive->affiliateLinks()->update(['status' => AffiliateLinkStatus::Inactive]);
        $this->createResult($session, $inactive, rank: 3, score: 20, explanation: 'Inactive.');

        $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->assertSee('Affiliate Gift', false)
            ->assertDontSee('Orphan Gift', false)
            ->assertDontSee('Inactive Link Gift', false);
    }

    public function test_missing_image_and_price_do_not_break_the_page(): void
    {
        $session = RecommendationSession::query()->create([]);
        $merchant = Merchant::query()->firstOrCreate(
            ['slug' => 'example-merchant'],
            [
                'name' => 'Example Merchant',
                'affiliate_network' => 'example',
                'is_active' => true,
            ],
        );
        $gift = Product::factory()->published()->create([
            'name' => 'Bare Gift',
            'slug' => 'bare-gift',
            'price_amount' => null,
            'short_description' => null,
        ]);
        AffiliateLink::query()->create([
            'product_id' => $gift->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://example.com/bare-gift',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);
        $this->createResult($session, $gift, rank: 1, score: 15, explanation: 'Matches Husband.');

        $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->assertSee('Bare Gift', false)
            ->assertSee('Matches Husband.', false)
            ->assertDontSee('Around ₹', false);
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
