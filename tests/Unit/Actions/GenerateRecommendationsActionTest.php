<?php

namespace Tests\Unit\Actions;

use App\Actions\Recommendation\GenerateRecommendationsAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\BudgetRange;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Merchant;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\RecommendationResult;
use App\Models\RecommendationSession;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class GenerateRecommendationsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_occasion_is_scored_and_is_not_a_hard_eligibility_filter(): void
    {
        $occasion = $this->occasion('Birthday');

        $matching = $this->gift([
            'name' => 'Birthday Gift',
            'slug' => 'birthday-gift',
            'price_amount' => '400.00',
        ]);
        $matching->occasions()->attach($occasion);

        $unrelated = $this->gift([
            'name' => 'Untagged Occasion Gift',
            'slug' => 'untagged-occasion-gift',
            'price_amount' => '400.00',
        ]);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'occasion_id' => $occasion->id,
        ]);

        $this->assertEqualsCanonicalizing(
            [$matching->id, $unrelated->id],
            $session->results->pluck('product_id')->all(),
        );

        $matchingResult = $session->results->firstWhere('product_id', $matching->id);
        $unrelatedResult = $session->results->firstWhere('product_id', $unrelated->id);

        $this->assertSame(
            config('gift_recommendations.weights.occasion_match'),
            $matchingResult->score_breakdown['occasion_match'],
        );
        $this->assertArrayNotHasKey('occasion_match', $unrelatedResult->score_breakdown);
        $this->assertSame(0.0, (float) $unrelatedResult->score);
    }

    public function test_it_only_includes_published_products_with_active_affiliate_links(): void
    {
        $occasion = $this->occasion('Birthday');

        $published = $this->gift(['name' => 'Published Gift', 'slug' => 'published-gift']);
        $published->occasions()->attach($occasion);

        $draft = $this->gift([
            'name' => 'Draft Gift',
            'slug' => 'draft-gift',
            'status' => ProductStatus::Draft,
            'published_at' => null,
        ]);
        $draft->occasions()->attach($occasion);

        $archived = $this->gift([
            'name' => 'Archived Gift',
            'slug' => 'archived-gift',
            'status' => ProductStatus::Archived,
        ]);
        $archived->occasions()->attach($occasion);

        $noLink = Product::factory()->published()->create([
            'name' => 'No Link Gift',
            'slug' => 'no-link-gift',
        ]);
        $noLink->occasions()->attach($occasion);

        $inactiveLink = $this->gift(['name' => 'Inactive Link Gift', 'slug' => 'inactive-link-gift'], linkStatus: AffiliateLinkStatus::Inactive);
        $inactiveLink->occasions()->attach($occasion);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'occasion_id' => $occasion->id,
        ]);

        $this->assertSame([$published->id], $session->results->pluck('product_id')->all());
    }

    public function test_it_applies_strict_optional_dimension_filtering(): void
    {
        $relationship = $this->relationship('Husband');

        $matching = $this->gift(['name' => 'Husband Gift', 'slug' => 'husband-gift']);
        $matching->relationships()->attach($relationship);

        $untagged = $this->gift(['name' => 'Untagged Gift', 'slug' => 'untagged-gift']);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $relationship->id,
        ]);

        $this->assertSame([$matching->id], $session->results->pluck('product_id')->all());
    }

    public function test_it_allows_untagged_products_when_strict_filtering_is_disabled(): void
    {
        Config::set('gift_recommendations.optional_dimensions_filter_strict', false);

        $relationship = $this->relationship('Husband');

        $matching = $this->gift(['name' => 'Husband Gift', 'slug' => 'husband-gift']);
        $matching->relationships()->attach($relationship);

        $untagged = $this->gift(['name' => 'Untagged Gift', 'slug' => 'untagged-gift', 'price_amount' => '100.00']);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $relationship->id,
        ]);

        $this->assertEqualsCanonicalizing(
            [$matching->id, $untagged->id],
            $session->results->pluck('product_id')->all(),
        );

        $untaggedResult = $session->results->firstWhere('product_id', $untagged->id);
        $this->assertSame(0.0, (float) $untaggedResult->score);
        $this->assertArrayNotHasKey('relationship_match', $untaggedResult->score_breakdown);
    }

    public function test_it_hard_filters_by_budget_range(): void
    {
        $budget = BudgetRange::query()->create([
            'name' => 'Under 1000',
            'slug' => 'under-1000',
            'min_amount' => 0,
            'max_amount' => 1000,
            'currency' => 'INR',
        ]);

        $inRange = $this->gift(['name' => 'In Range', 'slug' => 'in-range', 'price_amount' => '750.00']);
        $tooExpensive = $this->gift(['name' => 'Too Expensive', 'slug' => 'too-expensive', 'price_amount' => '1500.00']);
        $noPrice = $this->gift(['name' => 'No Price', 'slug' => 'no-price', 'price_amount' => null]);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'budget_range_id' => $budget->id,
        ]);

        $this->assertSame([$inRange->id], $session->results->pluck('product_id')->all());
        $this->assertNotContains($tooExpensive->id, $session->results->pluck('product_id'));
        $this->assertNotContains($noPrice->id, $session->results->pluck('product_id'));
    }

    public function test_it_scores_using_configured_weights(): void
    {
        $occasion = $this->occasion('Birthday');
        $relationship = $this->relationship('Husband');
        $recipientType = $this->recipientType('Adult');
        $profession = $this->profession('Engineer');
        $giftType = $this->giftType('Personalized');
        $interest = $this->interest('Technology');

        $product = $this->gift(['name' => 'Scored Gift', 'slug' => 'scored-gift']);
        $product->occasions()->attach($occasion);
        $product->relationships()->attach($relationship);
        $product->recipientTypes()->attach($recipientType);
        $product->professions()->attach($profession);
        $product->giftTypes()->attach($giftType);
        $product->interests()->attach($interest);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'occasion_id' => $occasion->id,
            'relationship_id' => $relationship->id,
            'recipient_type_id' => $recipientType->id,
            'profession_id' => $profession->id,
            'gift_type_id' => $giftType->id,
            'interest_ids' => [$interest->id],
        ]);

        $result = $session->results->first();
        $weights = config('gift_recommendations.weights');

        $expected = $weights['occasion_match']
            + $weights['relationship_match']
            + $weights['recipient_type_match']
            + $weights['profession_match']
            + $weights['gift_type_match']
            + $weights['interest_match'];

        $this->assertSame((float) $expected, (float) $result->score);
        $this->assertSame($expected, $result->score_breakdown['total']);
        $this->assertSame($weights['occasion_match'], $result->score_breakdown['occasion_match']);
    }

    public function test_it_caps_interests_at_max_interests_and_interest_score_max(): void
    {
        Config::set('gift_recommendations.max_interests', 3);
        Config::set('gift_recommendations.weights.interest_match', 10);
        Config::set('gift_recommendations.weights.interest_match_max', 30);

        $interests = collect([
            $this->interest('Technology'),
            $this->interest('Travel'),
            $this->interest('Cooking'),
            $this->interest('Fitness'),
        ]);

        $product = $this->gift(['name' => 'Multi Interest Gift', 'slug' => 'multi-interest-gift']);
        $product->interests()->attach($interests->pluck('id'));

        $session = app(GenerateRecommendationsAction::class)->execute([
            'interest_ids' => $interests->pluck('id')->all(),
        ]);

        $this->assertCount(3, $session->interests);
        $this->assertEqualsCanonicalizing(
            $interests->take(3)->pluck('id')->all(),
            $session->interests->pluck('id')->all(),
        );

        $result = $session->results->first();
        $this->assertSame(30, $result->score_breakdown['interest_match']);
        $this->assertSame(30.0, (float) $result->score);
    }

    public function test_it_applies_featured_weighting(): void
    {
        $occasion = $this->occasion('Birthday');

        $featured = $this->gift([
            'name' => 'Featured Gift',
            'slug' => 'featured-gift',
            'is_featured' => true,
            'price_amount' => '500.00',
        ]);
        $featured->occasions()->attach($occasion);

        $regular = $this->gift([
            'name' => 'Regular Gift',
            'slug' => 'regular-gift',
            'is_featured' => false,
            'price_amount' => '400.00',
        ]);
        $regular->occasions()->attach($occasion);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'occasion_id' => $occasion->id,
        ]);

        $this->assertSame([$featured->id, $regular->id], $session->results->pluck('product_id')->all());
        $this->assertSame(
            config('gift_recommendations.weights.featured_boost'),
            $session->results->first()->score_breakdown['featured_boost'],
        );
    }

    public function test_it_uses_deterministic_tie_breaking(): void
    {
        $occasion = $this->occasion('Birthday');

        $newerCheaper = $this->gift([
            'name' => 'Newer Cheaper',
            'slug' => 'newer-cheaper',
            'price_amount' => '300.00',
            'published_at' => now()->subDay(),
        ]);
        $newerCheaper->occasions()->attach($occasion);

        $olderCheaper = $this->gift([
            'name' => 'Older Cheaper',
            'slug' => 'older-cheaper',
            'price_amount' => '300.00',
            'published_at' => now()->subDays(5),
        ]);
        $olderCheaper->occasions()->attach($occasion);

        $expensive = $this->gift([
            'name' => 'Expensive',
            'slug' => 'expensive',
            'price_amount' => '900.00',
            'published_at' => now(),
        ]);
        $expensive->occasions()->attach($occasion);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'occasion_id' => $occasion->id,
        ]);

        $this->assertSame(
            [$newerCheaper->id, $olderCheaper->id, $expensive->id],
            $session->results->pluck('product_id')->all(),
        );
    }

    public function test_it_limits_results_to_configured_top_n(): void
    {
        Config::set('gift_recommendations.top_n', 2);

        $occasion = $this->occasion('Birthday');

        foreach (range(1, 4) as $index) {
            $product = $this->gift([
                'name' => "Gift {$index}",
                'slug' => "gift-{$index}",
                'price_amount' => (string) (100 * $index),
            ]);
            $product->occasions()->attach($occasion);
        }

        $session = app(GenerateRecommendationsAction::class)->execute([
            'occasion_id' => $occasion->id,
        ]);

        $this->assertCount(2, $session->results);
        $this->assertSame([1, 2], $session->results->pluck('rank')->all());
    }

    public function test_it_persists_session_and_empty_results_when_none_eligible(): void
    {
        $relationship = $this->relationship('Husband');

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $relationship->id,
        ]);

        $this->assertDatabaseHas('recommendation_sessions', [
            'id' => $session->id,
            'relationship_id' => $relationship->id,
        ]);
        $this->assertNotEmpty($session->uuid);
        $this->assertCount(0, $session->results);
        $this->assertSame(0, RecommendationResult::query()->count());
    }

    public function test_it_persists_recommendation_results_in_rank_order(): void
    {
        $occasion = $this->occasion('Birthday');
        $interest = $this->interest('Technology');

        $best = $this->gift([
            'name' => 'Best Gift',
            'slug' => 'best-gift',
            'is_featured' => true,
            'price_amount' => '200.00',
        ]);
        $best->occasions()->attach($occasion);
        $best->interests()->attach($interest);

        $second = $this->gift([
            'name' => 'Second Gift',
            'slug' => 'second-gift',
            'price_amount' => '200.00',
        ]);
        $second->occasions()->attach($occasion);
        $second->interests()->attach($interest);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'occasion_id' => $occasion->id,
            'interest_ids' => [$interest->id],
        ]);

        $this->assertInstanceOf(RecommendationSession::class, $session);
        $this->assertCount(1, $session->interests);

        $results = $session->results()->orderBy('rank')->get();

        $this->assertCount(2, $results);
        $this->assertSame(1, $results[0]->rank);
        $this->assertSame($best->id, $results[0]->product_id);
        $this->assertSame(2, $results[1]->rank);
        $this->assertSame($second->id, $results[1]->product_id);
        $this->assertNotEmpty($results[0]->explanation);
        $this->assertArrayHasKey('total', $results[0]->score_breakdown);
    }

    private function gift(array $attributes = [], AffiliateLinkStatus $linkStatus = AffiliateLinkStatus::Active): Product
    {
        $merchant = Merchant::query()->firstOrCreate(
            ['slug' => 'example-merchant'],
            [
                'name' => 'Example Merchant',
                'affiliate_network' => 'example',
                'is_active' => true,
            ],
        );

        $defaults = [
            'status' => ProductStatus::Published,
            'published_at' => now(),
            'price_amount' => '500.00',
            'price_currency' => 'INR',
            'is_featured' => false,
        ];

        $product = Product::query()->create(array_merge($defaults, $attributes));

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://example.com/'.$product->slug,
            'status' => $linkStatus,
            'is_primary' => true,
        ]);

        return $product;
    }

    private function occasion(string $name): Occasion
    {
        return Occasion::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
    }

    private function relationship(string $name): Relationship
    {
        return Relationship::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
    }

    private function recipientType(string $name): RecipientType
    {
        return RecipientType::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
    }

    private function profession(string $name): Profession
    {
        return Profession::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
    }

    private function giftType(string $name): GiftType
    {
        return GiftType::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
    }

    private function interest(string $name): Interest
    {
        return Interest::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
    }
}
