<?php

namespace Tests\Unit\Support;

use App\Support\SeoLandingPageCandidateCatalog;
use Tests\TestCase;

class SeoLandingPageCandidateCatalogTest extends TestCase
{
    public function test_candidate_definitions_are_composite_intents_with_unique_slugs(): void
    {
        $definitions = SeoLandingPageCandidateCatalog::definitions();
        $slugs = array_column($definitions, 'slug');

        $this->assertGreaterThanOrEqual(20, count($definitions));
        $this->assertLessThanOrEqual(30, count($definitions));
        $this->assertSame($slugs, array_unique($slugs));
        $this->assertContains('birthday-gifts-for-husband', $slugs);

        foreach ($definitions as $definition) {
            $dimensionCount = ($definition['relationship'] ? 1 : 0)
                + ($definition['occasion'] ? 1 : 0)
                + ($definition['recipient_type'] ? 1 : 0)
                + ($definition['profession'] ? 1 : 0)
                + ($definition['gift_type'] ? 1 : 0)
                + ($definition['interests'] !== [] ? 1 : 0);

            $this->assertGreaterThanOrEqual(2, $dimensionCount, $definition['slug']);
            $this->assertNull($definition['category']);
            $this->assertNull($definition['budget_range']);
        }
    }

    public function test_single_dimension_filters_are_rejected(): void
    {
        [$recommendation] = SeoLandingPageCandidateCatalog::recommend(
            slug: 'husband',
            filters: $this->filters(relationshipId: 1),
            productCount: 8,
            dimensionCount: 1,
            signatureTaken: false,
        );

        $this->assertSame(SeoLandingPageCandidateCatalog::REJECT, $recommendation);
    }

    public function test_existing_husband_birthday_slug_is_rejected_as_duplicate_seed(): void
    {
        [$recommendation, $reason] = SeoLandingPageCandidateCatalog::recommend(
            slug: 'birthday-gifts-for-husband',
            filters: $this->filters(relationshipId: 1, occasionId: 2),
            productCount: 5,
            dimensionCount: 2,
            signatureTaken: false,
        );

        $this->assertSame(SeoLandingPageCandidateCatalog::REJECT, $recommendation);
        $this->assertStringContainsString('Already seeded', $reason);
    }

    public function test_duplicate_filter_signature_is_rejected(): void
    {
        [$recommendation] = SeoLandingPageCandidateCatalog::recommend(
            slug: 'anniversary-gifts-for-husband',
            filters: $this->filters(relationshipId: 1, occasionId: 3),
            productCount: 4,
            dimensionCount: 2,
            signatureTaken: true,
        );

        $this->assertSame(SeoLandingPageCandidateCatalog::REJECT, $recommendation);
    }

    public function test_three_dimension_intents_are_held(): void
    {
        [$recommendation] = SeoLandingPageCandidateCatalog::recommend(
            slug: 'birthday-gifts-for-husband-who-loves-coffee',
            filters: $this->filters(relationshipId: 1, occasionId: 2, interestIds: [4]),
            productCount: 4,
            dimensionCount: 3,
            signatureTaken: false,
        );

        $this->assertSame(SeoLandingPageCandidateCatalog::HOLD, $recommendation);
    }

    public function test_thin_catalog_coverage_is_held(): void
    {
        [$zero] = SeoLandingPageCandidateCatalog::recommend(
            slug: 'wedding-gifts-for-newlyweds',
            filters: $this->filters(relationshipId: 5, occasionId: 6),
            productCount: 0,
            dimensionCount: 2,
            signatureTaken: false,
        );

        [$one] = SeoLandingPageCandidateCatalog::recommend(
            slug: 'birthday-gifts-for-sister',
            filters: $this->filters(relationshipId: 7, occasionId: 2),
            productCount: 1,
            dimensionCount: 2,
            signatureTaken: false,
        );

        $this->assertSame(SeoLandingPageCandidateCatalog::HOLD, $zero);
        $this->assertSame(SeoLandingPageCandidateCatalog::HOLD, $one);
    }

    public function test_composite_intent_with_enough_products_is_approved(): void
    {
        [$recommendation] = SeoLandingPageCandidateCatalog::recommend(
            slug: 'birthday-gifts-for-wife',
            filters: $this->filters(relationshipId: 8, occasionId: 2),
            productCount: 2,
            dimensionCount: 2,
            signatureTaken: false,
        );

        $this->assertSame(SeoLandingPageCandidateCatalog::APPROVE, $recommendation);
    }

    /**
     * @param  list<int>  $interestIds
     * @return array{
     *     occasion_id: int|null,
     *     relationship_id: int|null,
     *     recipient_type_id: int|null,
     *     profession_id: int|null,
     *     gift_type_id: int|null,
     *     category_id: int|null,
     *     budget_range_id: int|null,
     *     interest_ids: list<int>,
     * }
     */
    private function filters(
        ?int $relationshipId = null,
        ?int $occasionId = null,
        array $interestIds = [],
    ): array {
        return [
            'occasion_id' => $occasionId,
            'relationship_id' => $relationshipId,
            'recipient_type_id' => null,
            'profession_id' => null,
            'gift_type_id' => null,
            'category_id' => null,
            'budget_range_id' => null,
            'interest_ids' => $interestIds,
        ];
    }
}
