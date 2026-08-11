<?php

namespace Tests\Unit;

use Tests\TestCase;

class GiftRecommendationConfigTest extends TestCase
{
    public function test_strict_optional_dimension_filtering_is_enabled(): void
    {
        $this->assertTrue(config('gift_recommendations.optional_dimensions_filter_strict'));
    }

    public function test_result_limits_are_configured(): void
    {
        $this->assertSame(12, config('gift_recommendations.top_n'));
        $this->assertSame(3, config('gift_recommendations.max_interests'));
    }

    public function test_scoring_weights_are_configured(): void
    {
        $weights = config('gift_recommendations.weights');

        $this->assertIsArray($weights);
        $this->assertSame(25, $weights['occasion_match']);
        $this->assertSame(15, $weights['relationship_match']);
        $this->assertSame(15, $weights['recipient_type_match']);
        $this->assertSame(10, $weights['interest_match']);
        $this->assertSame(30, $weights['interest_match_max']);
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
}
