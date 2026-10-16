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
use App\Models\RecipientGender;
use App\Models\RecipientType;
use App\Models\RecommendationResult;
use App\Models\RecommendationSession;
use App\Models\Relationship;
use Database\Seeders\RecipientGenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class GenerateRecommendationsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_occasion_is_scored_and_is_not_a_hard_eligibility_filter(): void
    {
        $occasion = $this->occasion('Birthday');
        $relationship = $this->relationship('Husband');

        $both = $this->gift([
            'name' => 'Birthday Husband Gift',
            'slug' => 'birthday-husband-gift',
            'price_amount' => '400.00',
        ]);
        $both->occasions()->attach($occasion);
        $both->relationships()->attach($relationship);

        $relationshipOnly = $this->gift([
            'name' => 'Husband Only Gift',
            'slug' => 'husband-only-gift',
            'price_amount' => '400.00',
        ]);
        $relationshipOnly->relationships()->attach($relationship);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'occasion_id' => $occasion->id,
            'relationship_id' => $relationship->id,
        ]);

        $this->assertSame(
            [$both->id, $relationshipOnly->id],
            $this->rankedProductIds($session),
        );

        $bothResult = $session->results->firstWhere('product_id', $both->id);
        $relationshipOnlyResult = $session->results->firstWhere('product_id', $relationshipOnly->id);

        $this->assertSame(
            config('gift_recommendations.weights.occasion_match'),
            $bothResult->score_breakdown['occasion_match'],
        );
        $this->assertArrayNotHasKey('occasion_match', $relationshipOnlyResult->score_breakdown);
        $this->assertSame(RecommendationResult::GROUP_BEST, $bothResult->relevanceGroup());
        $this->assertSame(RecommendationResult::GROUP_RELAXED, $relationshipOnlyResult->relevanceGroup());
    }

    public function test_products_matching_no_answered_signal_are_not_returned(): void
    {
        $occasion = $this->occasion('Birthday');

        $matching = $this->gift(['name' => 'Birthday Gift', 'slug' => 'birthday-gift']);
        $matching->occasions()->attach($occasion);

        $irrelevant = $this->gift(['name' => 'Untagged Gift', 'slug' => 'untagged-gift']);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'occasion_id' => $occasion->id,
        ]);

        $this->assertSame([$matching->id], $session->results->pluck('product_id')->all());
        $this->assertNotContains($irrelevant->id, $session->results->pluck('product_id')->all());
    }

    public function test_multiple_interests_are_or_signals_and_more_matches_rank_higher(): void
    {
        $travel = $this->interest('Travel');
        $coffee = $this->interest('Coffee');
        $tech = $this->interest('Tech');
        $photography = $this->interest('Photography');
        $fitness = $this->interest('Fitness');

        $three = $this->gift(['name' => 'Three Interests', 'slug' => 'three-interests', 'price_amount' => '500.00']);
        $three->interests()->attach([$travel->id, $coffee->id, $tech->id]);

        $one = $this->gift(['name' => 'One Interest', 'slug' => 'one-interest', 'price_amount' => '500.00']);
        $one->interests()->attach([$fitness->id]);

        $none = $this->gift(['name' => 'No Interest Match', 'slug' => 'no-interest-match']);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'interest_ids' => [$travel->id, $coffee->id, $tech->id, $photography->id, $fitness->id],
        ]);

        $this->assertCount(5, $session->interests);
        $this->assertSame([$three->id, $one->id], $this->rankedProductIds($session));
        $this->assertNotContains($none->id, $session->results->pluck('product_id')->all());
    }

    public function test_fourth_and_fifth_interest_matches_add_only_a_small_bonus(): void
    {
        $interests = collect(['A', 'B', 'C', 'D', 'E'])->map(fn (string $name) => $this->interest($name));

        $three = $this->gift(['name' => 'Three', 'slug' => 'three']);
        $three->interests()->attach($interests->take(3)->pluck('id'));

        $five = $this->gift(['name' => 'Five', 'slug' => 'five']);
        $five->interests()->attach($interests->pluck('id'));

        $session = app(GenerateRecommendationsAction::class)->execute([
            'interest_ids' => $interests->pluck('id')->all(),
        ]);

        $weights = config('gift_recommendations.weights');
        $threeResult = $session->results->firstWhere('product_id', $three->id);
        $fiveResult = $session->results->firstWhere('product_id', $five->id);

        $this->assertSame($weights['interest_match_max'], $threeResult->score_breakdown['interest_match']);
        $this->assertSame(
            $weights['interest_match_max'] + 2 * $weights['interest_match_extra'],
            $fiveResult->score_breakdown['interest_match'],
        );
        $this->assertLessThan($weights['occasion_match'], $weights['interest_match_extra'] * 2);
        $this->assertSame([$five->id, $three->id], $this->rankedProductIds($session));
    }

    public function test_five_interest_matches_score_the_configured_maximum(): void
    {
        $interests = collect(['A', 'B', 'C', 'D', 'E'])->map(fn (string $name) => $this->interest($name));
        $weights = config('gift_recommendations.weights');
        $fullWeightMatches = intdiv($weights['interest_match_max'], $weights['interest_match']);
        $expected = $weights['interest_match_max'] + (5 - $fullWeightMatches) * $weights['interest_match_extra'];

        $product = $this->gift(['name' => 'Five Interests', 'slug' => 'five-interests']);
        $product->interests()->attach($interests->pluck('id'));

        $result = app(GenerateRecommendationsAction::class)
            ->execute(['interest_ids' => $interests->pluck('id')->all()])
            ->results->first();

        $this->assertSame($expected, $result->score_breakdown['interest_match']);
        $this->assertSame((float) $expected, (float) $result->score);
    }

    public function test_relationship_plus_occasion_scores_the_sum_of_their_weights(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $weights = config('gift_recommendations.weights');

        $product = $this->gift(['name' => 'Both', 'slug' => 'both']);
        $product->relationships()->attach($relationship);
        $product->occasions()->attach($occasion);

        $result = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
        ])->results->first();

        $this->assertSame((float) ($weights['relationship_match'] + $weights['occasion_match']), (float) $result->score);
    }

    public function test_selected_interests_outrank_generic_relationship_and_occasion_matches(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $interests = collect(['A', 'B', 'C', 'D', 'E'])->map(fn (string $name) => $this->interest($name));

        $strong = $this->gift(['name' => 'Relationship And Occasion', 'slug' => 'relationship-and-occasion', 'price_amount' => '900.00']);
        $strong->relationships()->attach($relationship);
        $strong->occasions()->attach($occasion);

        $interestOnly = $this->gift(['name' => 'Interests Only', 'slug' => 'interests-only', 'price_amount' => '100.00', 'is_featured' => true]);
        $interestOnly->interests()->attach($interests->pluck('id'));

        $input = [
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
            'interest_ids' => $interests->pluck('id')->all(),
        ];

        $session = app(GenerateRecommendationsAction::class)->execute($input);
        $this->assertSame([$interestOnly->id, $strong->id], $this->rankedProductIds($session));

        $groups = $session->results->sortBy('rank')->map(fn ($r) => $r->relevanceGroup())->values()->all();
        $this->assertSame([RecommendationResult::GROUP_INTEREST_ONLY, RecommendationResult::GROUP_RELATIONSHIP_OCCASION], $groups);

        // With room for only one gift, the selected-interest product wins the slot.
        Config::set('gift_recommendations.top_n', 1);
        $limited = app(GenerateRecommendationsAction::class)->execute($input);
        $this->assertSame([$interestOnly->id], $this->rankedProductIds($limited));
    }

    public function test_match_tier_ordering_wins_over_raw_score(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $profession = $this->profession('Engineer');
        $giftType = $this->giftType('Personalized');

        $tierBest = $this->gift(['name' => 'Tier Best', 'slug' => 'tier-best', 'price_amount' => '900.00']);
        $tierBest->relationships()->attach($relationship);
        $tierBest->occasions()->attach($occasion);

        $highScoreRelated = $this->gift(['name' => 'High Score Related', 'slug' => 'high-score-related', 'price_amount' => '100.00', 'is_featured' => true]);
        $highScoreRelated->relationships()->attach($relationship);
        $highScoreRelated->professions()->attach($profession);
        $highScoreRelated->giftTypes()->attach($giftType);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
            'profession_id' => $profession->id,
            'gift_type_id' => $giftType->id,
        ]);

        $best = $session->results->firstWhere('product_id', $tierBest->id);
        $related = $session->results->firstWhere('product_id', $highScoreRelated->id);

        $this->assertGreaterThan((float) $best->score, (float) $related->score);
        $this->assertSame([$tierBest->id, $highScoreRelated->id], $this->rankedProductIds($session));
    }

    public function test_one_matching_interest_keeps_a_gift_eligible_when_five_are_selected(): void
    {
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');
        $interests = collect(['A', 'B', 'C', 'D', 'E'])->map(fn (string $name) => $this->interest($name));

        $product = $this->gift(['name' => 'One Of Five', 'slug' => 'one-of-five']);
        $product->relationships()->attach($relationship);
        $product->occasions()->attach($occasion);
        $product->interests()->attach($interests->first());

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
            'interest_ids' => $interests->pluck('id')->all(),
        ]);

        $this->assertSame([$product->id], $this->rankedProductIds($session));
        $this->assertSame(RecommendationResult::GROUP_BEST, $session->results->first()->relevanceGroup());
    }

    public function test_sparse_exact_matches_are_broadened_in_relevance_group_order(): void
    {
        $relationship = $this->relationship('Husband');
        $other = $this->relationship('Wife');
        $occasion = $this->occasion('Birthday');
        $gaming = $this->interest('Gaming');

        $best = $this->gift(['name' => 'Best', 'slug' => 'best', 'price_amount' => '900.00']);
        $best->relationships()->attach($relationship);
        $best->occasions()->attach($occasion);
        $best->interests()->attach($gaming);

        $good = $this->gift(['name' => 'Good', 'slug' => 'good', 'price_amount' => '100.00']);
        $good->relationships()->attach($relationship);
        $good->occasions()->attach($occasion);

        $relatedRelationship = $this->gift(['name' => 'Related Relationship', 'slug' => 'related-relationship', 'price_amount' => '100.00']);
        $relatedRelationship->relationships()->attach($relationship);

        $relatedOccasion = $this->gift(['name' => 'Related Occasion', 'slug' => 'related-occasion', 'price_amount' => '100.00']);
        $relatedOccasion->occasions()->attach($occasion);
        $relatedOccasion->relationships()->attach($other);

        $relatedInterest = $this->gift(['name' => 'Related Interest', 'slug' => 'related-interest', 'price_amount' => '100.00', 'is_featured' => true]);
        $relatedInterest->interests()->attach($gaming);

        $unrelated = $this->gift(['name' => 'Unrelated', 'slug' => 'unrelated']);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
            'interest_ids' => [$gaming->id],
        ]);

        $results = $session->results->sortBy('rank')->values();

        $this->assertSame($best->id, $results[0]->product_id);
        $this->assertSame($relatedInterest->id, $results[1]->product_id);
        $this->assertSame($good->id, $results[2]->product_id);
        $this->assertSame(
            [
                RecommendationResult::GROUP_BEST,
                RecommendationResult::GROUP_INTEREST_ONLY,
                RecommendationResult::GROUP_RELATIONSHIP_OCCASION,
            ],
            [$results[0]->relevanceGroup(), $results[1]->relevanceGroup(), $results[2]->relevanceGroup()],
        );

        $related = $results->slice(3);
        $this->assertEqualsCanonicalizing(
            [$relatedRelationship->id, $relatedOccasion->id],
            $related->pluck('product_id')->all(),
        );
        $this->assertTrue($related->every(fn ($r) => $r->relevanceGroup() === RecommendationResult::GROUP_RELAXED));
        $this->assertNotContains($unrelated->id, $results->pluck('product_id')->all());
    }

    public function test_broadening_stops_once_enough_strong_matches_exist(): void
    {
        Config::set('gift_recommendations.top_n', 2);

        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');

        foreach (range(1, 2) as $index) {
            $strong = $this->gift(['name' => "Strong {$index}", 'slug' => "strong-{$index}", 'price_amount' => (string) (100 * $index)]);
            $strong->relationships()->attach($relationship);
            $strong->occasions()->attach($occasion);
        }

        $loose = $this->gift(['name' => 'Loose', 'slug' => 'loose', 'is_featured' => true, 'price_amount' => '1.00']);
        $loose->relationships()->attach($relationship);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
        ]);

        $this->assertNotContains($loose->id, $session->results->pluck('product_id')->all());
        $this->assertCount(2, $session->results);
    }

    public function test_profession_and_gift_type_are_soft_signals_not_filters(): void
    {
        $relationship = $this->relationship('Husband');
        $profession = $this->profession('Engineer');
        $giftType = $this->giftType('Personalized');

        $plain = $this->gift(['name' => 'Plain', 'slug' => 'plain']);
        $plain->relationships()->attach($relationship);

        $tagged = $this->gift(['name' => 'Tagged', 'slug' => 'tagged']);
        $tagged->relationships()->attach($relationship);
        $tagged->professions()->attach($profession);
        $tagged->giftTypes()->attach($giftType);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $relationship->id,
            'profession_id' => $profession->id,
            'gift_type_id' => $giftType->id,
        ]);

        $this->assertSame([$tagged->id, $plain->id], $this->rankedProductIds($session));
    }

    public function test_null_budget_applies_no_price_constraint(): void
    {
        $relationship = $this->relationship('Husband');

        $priced = $this->gift(['name' => 'Priced', 'slug' => 'priced', 'price_amount' => '99999.00']);
        $priced->relationships()->attach($relationship);

        $unpriced = $this->gift(['name' => 'Unpriced', 'slug' => 'unpriced', 'price_amount' => null]);
        $unpriced->relationships()->attach($relationship);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $relationship->id,
            'budget_range_id' => null,
        ]);

        $this->assertEqualsCanonicalizing(
            [$priced->id, $unpriced->id],
            $session->results->pluck('product_id')->all(),
        );
        $this->assertNull($session->budget_range_id);
    }

    public function test_chosen_budget_is_kept_while_broadening(): void
    {
        $budget = BudgetRange::query()->create([
            'name' => 'Under 500',
            'slug' => 'under-500',
            'min_amount' => null,
            'max_amount' => 500,
            'currency' => 'INR',
        ]);
        $relationship = $this->relationship('Husband');
        $occasion = $this->occasion('Birthday');

        $cheap = $this->gift(['name' => 'Cheap', 'slug' => 'cheap', 'price_amount' => '300.00']);
        $cheap->relationships()->attach($relationship);

        $expensive = $this->gift(['name' => 'Expensive Match', 'slug' => 'expensive-match', 'price_amount' => '900.00']);
        $expensive->relationships()->attach($relationship);
        $expensive->occasions()->attach($occasion);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $relationship->id,
            'occasion_id' => $occasion->id,
            'budget_range_id' => $budget->id,
        ]);

        $this->assertSame([$cheap->id], $session->results->pluck('product_id')->all());
    }

    public function test_results_are_deterministic_for_the_same_answers(): void
    {
        $relationship = $this->relationship('Husband');

        foreach (range(1, 5) as $index) {
            $gift = $this->gift(['name' => "Gift {$index}", 'slug' => "gift-{$index}", 'price_amount' => '300.00']);
            $gift->relationships()->attach($relationship);
        }

        $first = app(GenerateRecommendationsAction::class)->execute(['relationship_id' => $relationship->id]);
        $second = app(GenerateRecommendationsAction::class)->execute(['relationship_id' => $relationship->id]);

        $this->assertSame(
            $first->results->sortBy('rank')->pluck('product_id')->all(),
            $second->results->sortBy('rank')->pluck('product_id')->all(),
        );
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

    public function test_recipient_gender_is_eligibility_only_and_does_not_contribute_score(): void
    {
        $this->seed(RecipientGenderSeeder::class);

        $maleId = (int) RecipientGender::query()->where('slug', 'male')->value('id');
        $femaleId = (int) RecipientGender::query()->where('slug', 'female')->value('id');
        $unisexId = (int) RecipientGender::query()->where('slug', 'unisex')->value('id');
        $relationship = $this->relationship('Friends');

        $male = $this->gift(['name' => 'Male Gift', 'slug' => 'male-gift', 'price_amount' => '400.00']);
        $male->relationships()->attach($relationship);
        $male->recipientGenders()->attach($maleId);

        $unisex = $this->gift(['name' => 'Unisex Gift', 'slug' => 'unisex-gift', 'price_amount' => '400.00']);
        $unisex->relationships()->attach($relationship);
        $unisex->recipientGenders()->attach($unisexId);

        $female = $this->gift(['name' => 'Female Gift', 'slug' => 'female-gift', 'price_amount' => '400.00']);
        $female->relationships()->attach($relationship);
        $female->recipientGenders()->attach($femaleId);

        $maleSession = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $relationship->id,
            'recipient_gender_id' => $maleId,
        ]);

        $this->assertEqualsCanonicalizing(
            [$male->id, $unisex->id],
            $maleSession->results->pluck('product_id')->all(),
        );
        $this->assertNotContains($female->id, $maleSession->results->pluck('product_id')->all());

        foreach ($maleSession->results as $result) {
            $this->assertArrayNotHasKey('recipient_gender_match', $result->score_breakdown);
            $this->assertSame(
                (float) config('gift_recommendations.weights.relationship_match'),
                (float) $result->score,
            );
        }

        $femaleSession = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $relationship->id,
            'recipient_gender_id' => $femaleId,
        ]);

        $this->assertEqualsCanonicalizing(
            [$female->id, $unisex->id],
            $femaleSession->results->pluck('product_id')->all(),
        );
        $this->assertNotContains($male->id, $femaleSession->results->pluck('product_id')->all());

        foreach ($femaleSession->results as $result) {
            $this->assertArrayNotHasKey('recipient_gender_match', $result->score_breakdown);
            $this->assertSame(
                (float) config('gift_recommendations.weights.relationship_match'),
                (float) $result->score,
            );
        }
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

    public function test_audit_case_a_self_care_products_outrank_generic_relationship_and_occasion_gifts(): void
    {
        $friends = $this->relationship('Friends');
        $other = $this->relationship('Wife');
        $justBecause = $this->occasion('Just Because');
        $selfCare = $this->interest('Self-Care & Wellness');

        // Generic: Relationship + Occasion, no Self-Care. Cheap and featured so price/featured cannot rescue it.
        $generic = $this->gift(['name' => 'Generic Wallet Card', 'slug' => 'generic-wallet-card', 'price_amount' => '1.00', 'is_featured' => true]);
        $generic->relationships()->attach($friends);
        $generic->occasions()->attach($justBecause);

        // Occasion + Self-Care (not Friends).
        $occasionInterest = $this->gift(['name' => 'Diffuser', 'slug' => 'diffuser', 'price_amount' => '900.00']);
        $occasionInterest->relationships()->attach($other);
        $occasionInterest->occasions()->attach($justBecause);
        $occasionInterest->interests()->attach($selfCare);

        // Self-Care only.
        $interestOnly = $this->gift(['name' => 'Serum', 'slug' => 'serum', 'price_amount' => '900.00']);
        $interestOnly->interests()->attach($selfCare);

        // Broader fallback: relationship only, nothing else.
        $fallback = $this->gift(['name' => 'Fallback Gift', 'slug' => 'fallback-gift', 'price_amount' => '1.00', 'is_featured' => true]);
        $fallback->relationships()->attach($friends);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $friends->id,
            'occasion_id' => $justBecause->id,
            'interest_ids' => [$selfCare->id],
        ]);

        $this->assertSame(
            [$occasionInterest->id, $interestOnly->id, $generic->id, $fallback->id],
            $this->rankedProductIds($session),
        );
        $this->assertSame(
            [
                RecommendationResult::GROUP_STRONG_INTEREST,
                RecommendationResult::GROUP_INTEREST_ONLY,
                RecommendationResult::GROUP_RELATIONSHIP_OCCASION,
                RecommendationResult::GROUP_RELAXED,
            ],
            $session->results->sortBy('rank')->map(fn ($r) => $r->relevanceGroup())->values()->all(),
        );
    }

    public function test_audit_case_b_interest_matches_are_retrieved_even_when_relationship_and_occasion_fill_top_n(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $gaming = $this->interest('Gaming');
        $topN = (int) config('gift_recommendations.top_n');

        $generic = collect(range(1, $topN))->map(function (int $index) use ($husband, $birthday) {
            $gift = $this->gift(['name' => "Generic {$index}", 'slug' => "generic-{$index}", 'price_amount' => '100.00', 'is_featured' => true]);
            $gift->relationships()->attach($husband);
            $gift->occasions()->attach($birthday);

            return $gift;
        });

        $gamingOnly = collect(range(1, 3))->map(function (int $index) use ($gaming) {
            $gift = $this->gift(['name' => "Gaming {$index}", 'slug' => "gaming-{$index}", 'price_amount' => '9000.00']);
            $gift->interests()->attach($gaming);

            return $gift;
        });

        $perfect = $this->gift(['name' => 'Husband Birthday Gaming', 'slug' => 'husband-birthday-gaming', 'price_amount' => '9000.00']);
        $perfect->relationships()->attach($husband);
        $perfect->occasions()->attach($birthday);
        $perfect->interests()->attach($gaming);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
            'interest_ids' => [$gaming->id],
        ]);

        $ranked = $this->rankedProductIds($session);

        $this->assertCount($topN, $ranked);
        $this->assertSame($perfect->id, $ranked[0]);
        $this->assertEqualsCanonicalizing($gamingOnly->pluck('id')->all(), array_slice($ranked, 1, 3));

        // Generic Husband + Birthday gifts only fill the remaining slots.
        $this->assertCount($topN - 4, array_intersect($ranked, $generic->pluck('id')->all()));
    }

    public function test_interest_matches_are_retrieved_when_top_n_is_smaller_than_the_relationship_and_occasion_pool(): void
    {
        Config::set('gift_recommendations.top_n', 2);

        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $gaming = $this->interest('Gaming');

        foreach (range(1, 5) as $index) {
            $generic = $this->gift(['name' => "Generic {$index}", 'slug' => "generic-{$index}"]);
            $generic->relationships()->attach($husband);
            $generic->occasions()->attach($birthday);
        }

        $first = $this->gift(['name' => 'Gaming One', 'slug' => 'gaming-one']);
        $first->interests()->attach($gaming);
        $second = $this->gift(['name' => 'Gaming Two', 'slug' => 'gaming-two']);
        $second->interests()->attach($gaming);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
            'interest_ids' => [$gaming->id],
        ]);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->rankedProductIds($session));
    }

    public function test_more_matched_interests_rank_higher_within_the_same_relevance_group(): void
    {
        $travel = $this->interest('Travel');
        $gaming = $this->interest('Gaming');
        $tech = $this->interest('Tech');
        $coffee = $this->interest('Coffee');

        // The single-interest gift is cheaper, so price ordering cannot explain the result.
        $one = $this->gift(['name' => 'One Interest', 'slug' => 'one-interest', 'price_amount' => '100.00']);
        $one->interests()->attach($tech);

        $two = $this->gift(['name' => 'Two Interests', 'slug' => 'two-interests', 'price_amount' => '900.00']);
        $two->interests()->attach([$travel->id, $gaming->id]);

        // Matches nothing selected: not retrieved at all (no other signals selected).
        $none = $this->gift(['name' => 'No Match', 'slug' => 'no-match']);
        $none->interests()->attach($coffee);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'interest_ids' => [$travel->id, $gaming->id, $tech->id],
        ]);

        $this->assertSame([$two->id, $one->id], $this->rankedProductIds($session));
        $this->assertSame(
            [RecommendationResult::GROUP_BEST, RecommendationResult::GROUP_BEST],
            $session->results->sortBy('rank')->map(fn ($r) => $r->relevanceGroup())->values()->all(),
        );
    }

    public function test_matching_one_selected_interest_is_enough_to_be_retrieved(): void
    {
        $husband = $this->relationship('Husband');
        $travel = $this->interest('Travel');
        $coffee = $this->interest('Coffee');
        $tech = $this->interest('Tech');

        $coffeeOnly = $this->gift(['name' => 'Coffee Only', 'slug' => 'coffee-only']);
        $coffeeOnly->interests()->attach($coffee);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $husband->id,
            'interest_ids' => [$travel->id, $coffee->id, $tech->id],
        ]);

        $this->assertSame([$coffeeOnly->id], $this->rankedProductIds($session));
        $this->assertSame(RecommendationResult::GROUP_INTEREST_ONLY, $session->results->first()->relevanceGroup());
    }

    public function test_price_is_a_late_tie_break_after_interest_overlap_and_featured(): void
    {
        $fitness = $this->interest('Fitness');
        $reading = $this->interest('Reading');

        // Force score ties: featured_boost == interest_match, so 1 interest + featured == 2 interests.
        Config::set('gift_recommendations.weights.featured_boost', 10);

        $cheapFeatured = $this->gift(['name' => 'Cheap Featured', 'slug' => 'cheap-featured', 'price_amount' => '100.00', 'is_featured' => true]);
        $cheapFeatured->interests()->attach($fitness);

        $pricierTwoInterests = $this->gift(['name' => 'Pricier Two Interests', 'slug' => 'pricier-two-interests', 'price_amount' => '900.00']);
        $pricierTwoInterests->interests()->attach([$fitness->id, $reading->id]);

        $overlapSession = app(GenerateRecommendationsAction::class)->execute(['interest_ids' => [$fitness->id, $reading->id]]);

        $this->assertEquals(
            $overlapSession->results->firstWhere('product_id', $cheapFeatured->id)->score,
            $overlapSession->results->firstWhere('product_id', $pricierTwoInterests->id)->score,
        );
        $this->assertSame([$pricierTwoInterests->id, $cheapFeatured->id], $this->rankedProductIds($overlapSession));

        // Equal score and equal overlap: featured beats the cheaper price.
        Config::set('gift_recommendations.weights.featured_boost', 20);
        $engineer = $this->profession('Engineer');

        $pricierFeatured = $this->gift(['name' => 'Pricier Featured', 'slug' => 'pricier-featured', 'price_amount' => '900.00', 'is_featured' => true]);
        $pricierFeatured->interests()->attach($fitness);

        $cheaperProfession = $this->gift(['name' => 'Cheaper Profession', 'slug' => 'cheaper-profession', 'price_amount' => '100.00']);
        $cheaperProfession->interests()->attach($fitness);
        $cheaperProfession->professions()->attach($engineer);

        $featuredSession = app(GenerateRecommendationsAction::class)->execute([
            'profession_id' => $engineer->id,
            'interest_ids' => [$fitness->id],
        ]);

        $this->assertEquals(
            $featuredSession->results->firstWhere('product_id', $pricierFeatured->id)->score,
            $featuredSession->results->firstWhere('product_id', $cheaperProfession->id)->score,
        );
        $this->assertSame(
            [$pricierFeatured->id, $cheaperProfession->id],
            $featuredSession->results->sortBy('rank')->whereIn('product_id', [$pricierFeatured->id, $cheaperProfession->id])->pluck('product_id')->values()->all(),
        );
    }

    public function test_without_interests_the_interest_first_model_is_not_applied(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');

        $full = $this->gift(['name' => 'Full Match', 'slug' => 'full-match', 'price_amount' => '900.00']);
        $full->relationships()->attach($husband);
        $full->occasions()->attach($birthday);

        $partial = $this->gift(['name' => 'Partial Match', 'slug' => 'partial-match', 'price_amount' => '100.00', 'is_featured' => true]);
        $partial->relationships()->attach($husband);

        $session = app(GenerateRecommendationsAction::class)->execute([
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
        ]);

        $this->assertSame([$full->id, $partial->id], $this->rankedProductIds($session));
        $this->assertSame(
            [RecommendationResult::GROUP_BEST, RecommendationResult::GROUP_RELAXED],
            $session->results->sortBy('rank')->map(fn ($r) => $r->relevanceGroup())->values()->all(),
        );
    }

    public function test_selected_interest_retrieval_keeps_every_hard_constraint(): void
    {
        $this->seed(RecipientGenderSeeder::class);

        $maleId = (int) RecipientGender::query()->where('slug', 'male')->value('id');
        $femaleId = (int) RecipientGender::query()->where('slug', 'female')->value('id');
        $budget = BudgetRange::query()->create([
            'name' => 'Under 1000',
            'slug' => 'under-1000',
            'min_amount' => 0,
            'max_amount' => 1000,
            'currency' => 'INR',
        ]);
        $gaming = $this->interest('Gaming');

        $eligible = $this->gift(['name' => 'Eligible', 'slug' => 'eligible', 'price_amount' => '500.00']);
        $eligible->recipientGenders()->attach($maleId);

        $excluded = [
            'draft' => $this->gift(['name' => 'Draft', 'slug' => 'draft', 'status' => ProductStatus::Draft, 'published_at' => null]),
            'archived' => $this->gift(['name' => 'Archived', 'slug' => 'archived', 'status' => ProductStatus::Archived]),
            'inactive link' => $this->gift(['name' => 'Inactive Link', 'slug' => 'inactive-link'], linkStatus: AffiliateLinkStatus::Inactive),
            'over budget' => $this->gift(['name' => 'Over Budget', 'slug' => 'over-budget', 'price_amount' => '5000.00']),
            'wrong gender' => $this->gift(['name' => 'Female Only', 'slug' => 'female-only', 'price_amount' => '500.00']),
        ];
        $excluded['wrong gender']->recipientGenders()->attach($femaleId);

        $noLink = Product::factory()->published()->create(['name' => 'No Link', 'slug' => 'no-link', 'price_amount' => '500.00']);
        $excluded['no link'] = $noLink;

        $eligible->interests()->attach($gaming);
        foreach ($excluded as $product) {
            $product->interests()->attach($gaming);
        }

        $session = app(GenerateRecommendationsAction::class)->execute([
            'budget_range_id' => $budget->id,
            'recipient_gender_id' => $maleId,
            'interest_ids' => [$gaming->id],
        ]);

        $this->assertSame([$eligible->id], $this->rankedProductIds($session));
    }

    /**
     * @return list<int>
     */
    private function rankedProductIds(RecommendationSession $session): array
    {
        return $session->results->sortBy('rank')->pluck('product_id')->values()->all();
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
