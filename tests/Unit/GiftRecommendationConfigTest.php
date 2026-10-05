<?php

namespace Tests\Unit;

use Tests\TestCase;

class GiftRecommendationConfigTest extends TestCase
{
    public function test_strict_optional_dimension_flag_no_longer_exists(): void
    {
        $this->assertNull(config('gift_recommendations.optional_dimensions_filter_strict'));
    }

    public function test_result_limits_are_configured(): void
    {
        $this->assertSame(12, config('gift_recommendations.top_n'));
        $this->assertSame(5, config('gift_recommendations.max_interests'));
    }

    public function test_scoring_weights_are_configured(): void
    {
        $weights = config('gift_recommendations.weights');

        $this->assertIsArray($weights);
        $this->assertSame(25, $weights['occasion_match']);
        $this->assertSame(15, $weights['relationship_match']);
        $this->assertSame(15, $weights['recipient_type_match']);
        $this->assertArrayNotHasKey('recipient_gender_match', $weights);
        $this->assertSame(10, $weights['interest_match']);
        $this->assertSame(30, $weights['interest_match_max']);
        $this->assertSame(2, $weights['interest_match_extra']);
        $this->assertSame(20, $weights['profession_match']);
        $this->assertSame(15, $weights['gift_type_match']);
        $this->assertSame(5, $weights['featured_boost']);
    }

    public function test_tie_breakers_are_configured(): void
    {
        $this->assertSame(
            ['score', 'price_amount', 'published_at', 'id'],
            config('gift_recommendations.tie_breakers'),
        );
    }

    public function test_scoring_invariants_hold_for_the_configured_weights(): void
    {
        $weights = config('gift_recommendations.weights');
        $maxInterests = config('gift_recommendations.max_interests');

        $fullWeightMatches = intdiv($weights['interest_match_max'], $weights['interest_match']);
        $interestCeiling = $weights['interest_match_max']
            + max(0, $maxInterests - $fullWeightMatches) * $weights['interest_match_extra'];
        $relationshipAndOccasion = $weights['relationship_match'] + $weights['occasion_match'];

        $this->assertSame(34, $interestCeiling);
        $this->assertSame(40, $relationshipAndOccasion);
        $this->assertGreaterThan($interestCeiling, $relationshipAndOccasion);
        $this->assertLessThan($weights['occasion_match'], $weights['interest_match_extra'] * ($maxInterests - $fullWeightMatches));
    }
}
