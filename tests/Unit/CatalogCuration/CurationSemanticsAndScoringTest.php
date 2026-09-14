<?php

namespace Tests\Unit\CatalogCuration;

use App\Actions\CatalogCuration\AmendCurationReviewAction;
use App\Actions\CatalogCuration\BuildCurationIssuesAction;
use App\Actions\CatalogCuration\BuildCurationTaxonomyDifferencesAction;
use App\Actions\CatalogCuration\CalculateGiftScoreAction;
use App\Actions\CatalogCuration\RecommendProductCurationAction;
use App\Actions\CatalogCuration\ResolveCatalogRoleAction;
use App\Actions\CatalogCuration\ValidateCurationSemanticEvaluationAction;
use App\CatalogCuration\ProductCurationEvidence;
use App\CatalogCuration\ProductCurationSemanticPrompt;
use App\CommercialSourcing\CommercialEnrichmentException;
use App\CommercialSourcing\CommercialTaxonomyCatalog;
use App\Enums\CatalogRole;
use App\Enums\CurationFitStrength;
use App\Enums\CurationIssueCode;
use App\Enums\CurationRecommendation;
use App\Enums\GiftIntent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CurationSemanticsAndScoringTest extends TestCase
{
    public function test_gift_score_is_deterministic_and_unknown_value_for_money_is_null(): void
    {
        $evidence = $this->evidence();
        $result = app(CalculateGiftScoreAction::class)->execute($evidence, [
            'gift_components' => [
                'recipient_desirability' => 20,
                'thoughtfulness_emotional_potential' => 15,
                'uniqueness' => 15,
                'value_for_money' => null,
                'visual_gifting_appeal' => 10,
                'practical_usefulness' => 10,
                'social_media_shareability' => 5,
            ],
        ]);

        $this->assertSame(75, $result['score']);
        $this->assertSame([
            'score' => null,
            'max' => 15,
            'evidence_status' => 'unknown',
            'source' => 'ai_semantic',
        ], $result['components']['value_for_money']);
        $this->assertSame([
            'score' => 0,
            'max' => 10,
            'evidence_status' => 'missing',
            'source' => 'database',
        ], $result['components']['product_vendor_confidence']);
    }

    public function test_numeric_value_for_money_is_preserved_when_semantic_evidence_supports_it(): void
    {
        $payload = $this->payload();
        $payload['gift_components']['value_for_money'] = 11;

        $validated = app(ValidateCurationSemanticEvaluationAction::class)->execute(
            $payload,
            $this->evidence(),
            $this->catalog(),
        );
        $result = app(CalculateGiftScoreAction::class)->execute($this->evidence(), $validated['evaluation']);

        $this->assertSame(11, $result['components']['value_for_money']['score']);
        $this->assertSame('supported', $result['components']['value_for_money']['evidence_status']);
    }

    #[DataProvider('invalidPayloads')]
    public function test_semantic_validation_rejects_invalid_or_out_of_range_values(callable $mutate): void
    {
        $payload = $this->payload();
        $mutate($payload);

        $this->expectException(CommercialEnrichmentException::class);
        app(ValidateCurationSemanticEvaluationAction::class)->execute(
            $payload,
            $this->evidence(),
            $this->catalog(),
        );
    }

    public static function invalidPayloads(): array
    {
        return [
            'component over maximum is rejected rather than clamped' => [
                function (array &$payload): void {
                    $payload['gift_components']['recipient_desirability'] = 21;
                },
            ],
            'null is rejected outside value for money' => [
                function (array &$payload): void {
                    $payload['gift_components']['uniqueness'] = null;
                },
            ],
            'more than three intents' => [
                function (array &$payload): void {
                    $payload['gift_intents'] = ['sentimental', 'practical', 'celebratory', 'romantic'];
                },
            ],
        ];
    }

    public function test_missing_current_taxonomy_evaluation_becomes_a_material_review_issue(): void
    {
        $payload = $this->payload();
        $payload['current_taxonomy_evaluations']['relationships'] = [];

        $validated = app(ValidateCurationSemanticEvaluationAction::class)->execute(
            $payload,
            $this->evidence(),
            $this->catalog(),
        );

        $this->assertSame('missing_current_assignment_evaluation', $validated['issues'][0]['code']);
        $this->assertSame('material', $validated['issues'][0]['severity']);
        $this->assertTrue($validated['issues'][0]['context']['forces_human_review']);
    }

    public function test_unknown_suggestions_are_dropped_and_canonical_rows_are_resolved_without_creation(): void
    {
        $payload = $this->payload();
        $payload['taxonomy_suggestions']['relationships'] = [
            ['name' => 'Unknown', 'slug' => 'unknown', 'strength' => 'strong', 'reason' => 'Possible fit.'],
            ['name' => 'Mother', 'slug' => 'mother', 'strength' => 'medium', 'reason' => 'Contextual fit.'],
        ];

        $validated = app(ValidateCurationSemanticEvaluationAction::class)->execute(
            $payload,
            $this->evidence(),
            $this->catalog(),
        );

        $suggestions = $validated['evaluation']['taxonomy_suggestions']['relationships'];
        $this->assertCount(1, $suggestions);
        $this->assertSame([
            'id' => 2,
            'name' => 'Mother',
            'slug' => 'mother',
            'strength' => 'medium',
            'reason' => 'Contextual fit.',
        ], $suggestions[0]);
        $this->assertSame('unresolved_taxonomy_label', $validated['issues'][0]['code']);
        $this->assertSame('tea-infuser', $validated['evaluation']['concept_key']);
    }

    public function test_blank_concept_key_candidate_falls_back_to_the_normalized_label(): void
    {
        $payload = $this->payload();
        $payload['concept_key_candidate'] = '';
        $payload['concept_label_candidate'] = 'Premium Leather Wallet';

        $validated = app(ValidateCurationSemanticEvaluationAction::class)->execute(
            $payload,
            $this->evidence(),
            $this->catalog(),
        );

        $this->assertSame('premium-leather-wallet', $validated['evaluation']['concept_key']);
        $this->assertSame('Premium Leather Wallet', $validated['evaluation']['concept_label']);
    }

    public function test_concept_alias_groups_feature_specific_candidates_and_preserves_an_explainable_label(): void
    {
        $payload = $this->payload();
        $payload['concept_key_candidate'] = 'Waterproof Mini Bluetooth Speaker';
        $payload['concept_label_candidate'] = 'Waterproof Mini Bluetooth Speaker';

        $validated = app(ValidateCurationSemanticEvaluationAction::class)->execute(
            $payload,
            $this->evidence(),
            $this->catalog(),
        );

        $this->assertSame('portable-bluetooth-speaker', $validated['evaluation']['concept_key']);
        $this->assertSame('Portable Bluetooth Speaker', $validated['evaluation']['concept_label']);
    }

    public function test_missing_evidence_cannot_leave_a_candidate_recommendation_unamended(): void
    {
        $review = app(AmendCurationReviewAction::class)->execute(
            CurationRecommendation::ReplaceCandidate,
            [[
                'code' => 'missing_commerce_evidence',
                'severity' => 'warning',
                'message' => 'Missing.',
                'context' => [],
            ]],
            60,
            30,
        );

        $this->assertSame(CurationRecommendation::Review, $review['recommendation']);
        $this->assertTrue($review['requires_human_review']);
    }

    public function test_missing_evidence_overrides_candidate_evidence(): void
    {
        $issues = [
            ['code' => 'missing_commerce_evidence', 'context' => ['missing' => ['price']]],
            ['code' => 'concept_oversaturated', 'context' => ['differentiation_unclear' => true]],
        ];

        $recommendation = app(RecommendProductCurationAction::class)->execute(60, 40, $issues, false);

        $this->assertSame(CurationRecommendation::Review, $recommendation);
        $this->assertSame(
            CurationRecommendation::Review,
            app(AmendCurationReviewAction::class)
                ->execute(CurationRecommendation::ReplaceCandidate, $issues, 60, 40)['recommendation'],
        );
    }

    public function test_taxonomy_differences_are_advisory_additions_and_removals(): void
    {
        $semantic = $this->payload();
        $semantic['current_taxonomy_evaluations']['relationships'][0]['id'] = 1;
        $semantic['current_taxonomy_evaluations']['relationships'][0]['strength'] = 'weak';
        $semantic['current_taxonomy_evaluations']['relationships'][0]['misleading_on_targeted_landing_page'] = false;
        $semantic['taxonomy_suggestions']['relationships'] = [[
            'id' => 2,
            'name' => 'Mother',
            'slug' => 'mother',
            'strength' => 'strong',
            'reason' => 'A natural recipient fit.',
        ]];

        $differences = app(BuildCurationTaxonomyDifferencesAction::class)->execute($this->evidence(), $semantic);

        $this->assertSame(['remove', 'add'], array_column($differences, 'action'));
        $this->assertSame(['father', 'mother'], array_column(array_column($differences, 'taxonomy'), 'slug'));
        $this->assertSame(['assigned', 'not_assigned'], array_column($differences, 'current_status'));
        $this->assertSame(['weak', 'strong'], array_column($differences, 'audit_strength'));
    }

    #[DataProvider('currentFitSeverityCases')]
    public function test_current_fit_severity_is_centralized_by_dimension_and_misleading_signal(
        string $dimension,
        string $strength,
        bool $misleading,
        string $expectedSeverity,
        bool $expectedReview,
    ): void {
        $evidence = $this->evidenceForDimension($dimension);
        $semantic = $this->emptyTaxonomySemantic();
        $semantic['current_taxonomy_evaluations'][$dimension] = [[
            ...$evidence->taxonomy[$dimension][0],
            'strength' => $strength,
            'reason' => 'Calibrated fit reason.',
            'misleading_on_targeted_landing_page' => $misleading,
        ]];

        $differences = app(BuildCurationTaxonomyDifferencesAction::class)->execute($evidence, $semantic);

        if ($strength === 'strong') {
            $this->assertSame([], $differences);

            return;
        }

        $this->assertSame($expectedSeverity, $differences[0]['severity']);
        $this->assertSame($expectedReview, $differences[0]['forces_human_review']);
    }

    public static function currentFitSeverityCases(): array
    {
        return [
            'strong has no finding' => ['relationships', 'strong', false, 'none', false],
            'medium relationship is advisory' => ['relationships', 'medium', false, 'advisory', false],
            'weak broad relationship is advisory' => ['relationships', 'weak', false, 'advisory', false],
            'weak misleading relationship is material' => ['relationships', 'weak', true, 'material', true],
            'weak misleading occasion is material' => ['occasions', 'weak', true, 'material', true],
            'weak incidental interest is advisory' => ['interests', 'weak', false, 'advisory', false],
            'weak gift type is material' => ['gift_types', 'weak', false, 'material', true],
        ];
    }

    public function test_audit_only_suggestion_is_advisory_and_multiple_advisories_do_not_force_review(): void
    {
        $evidence = $this->evidenceForDimension('relationships');
        $semantic = $this->emptyTaxonomySemantic();
        $semantic['current_taxonomy_evaluations']['relationships'] = [[
            ...$evidence->taxonomy['relationships'][0],
            'strength' => 'medium',
            'reason' => 'Useful in a specific recipient context.',
            'misleading_on_targeted_landing_page' => false,
        ]];
        $semantic['taxonomy_suggestions']['occasions'] = [[
            'id' => 22,
            'name' => 'Birthday',
            'slug' => 'birthday',
            'strength' => 'strong',
            'reason' => 'A natural birthday gift.',
        ]];

        $differences = app(BuildCurationTaxonomyDifferencesAction::class)->execute($evidence, $semantic);
        $builtIssues = app(BuildCurationIssuesAction::class)->execute(
            $evidence,
            [],
            $differences,
            75,
            70,
            'high',
            [
                'value_for_money' => ['score' => 10, 'evidence_status' => 'supported'],
                'product_vendor_confidence' => ['score' => 10],
            ],
            ['why_this_gift' => 'Useful for someone who values this practical item in a specific daily routine.'],
            [
                'snapshot' => ['signals' => []],
                'peer_counts' => [],
                'peer_product_ids' => [],
            ],
        );
        $issues = collect($builtIssues)
            ->where('code', 'taxonomy_fit_advisory')
            ->values()
            ->all();

        $this->assertCount(2, $differences);
        $this->assertCount(2, $issues);
        $this->assertSame(['advisory', 'advisory'], array_column($differences, 'severity'));
        $this->assertSame(
            CurationRecommendation::Keep,
            app(RecommendProductCurationAction::class)->execute(75, 70, $issues, false),
        );
        $this->assertFalse(
            app(AmendCurationReviewAction::class)
                ->execute(CurationRecommendation::Keep, $issues, 75, 70)['requires_human_review'],
        );
    }

    public function test_low_confidence_still_forces_review_when_taxonomy_differences_are_advisory(): void
    {
        $issues = [
            [
                'code' => 'taxonomy_fit_advisory',
                'severity' => 'advisory',
                'context' => ['forces_human_review' => false],
            ],
            [
                'code' => 'low_ai_confidence',
                'severity' => 'warning',
                'context' => [],
            ],
        ];

        $this->assertTrue(
            app(AmendCurationReviewAction::class)
                ->execute(CurationRecommendation::Keep, $issues, 75, 70)['requires_human_review'],
        );
    }

    public function test_contract_enums_have_exact_values(): void
    {
        $this->assertSame(
            ['romantic', 'sentimental', 'personalised', 'practical', 'fun', 'surprising', 'premium', 'experience', 'self_care', 'celebratory'],
            array_column(GiftIntent::cases(), 'value'),
        );
        $this->assertSame(['strong', 'medium', 'weak'], array_column(CurationFitStrength::cases(), 'value'));
        $this->assertSame(
            ['feature', 'keep', 'keep_niche', 'review', 'replace_candidate', 'remove_candidate'],
            array_column(CurationRecommendation::cases(), 'value'),
        );
        $this->assertSame(
            ['best_overall', 'best_value', 'premium_pick', 'unique_pick', 'niche_pick', 'undifferentiated'],
            array_column(CatalogRole::cases(), 'value'),
        );
    }

    #[DataProvider('recommendationCases')]
    public function test_recommendation_thresholds(
        int $giftScore,
        int $catalogScore,
        array $issues,
        bool $niche,
        CurationRecommendation $expected,
    ): void {
        $this->assertSame(
            $expected,
            app(RecommendProductCurationAction::class)->execute($giftScore, $catalogScore, $issues, $niche),
        );
    }

    public static function recommendationCases(): array
    {
        return [
            'feature' => [80, 70, [], false, CurationRecommendation::Feature],
            'keep' => [65, 60, [], false, CurationRecommendation::Keep],
            'keep niche' => [55, 70, [], true, CurationRecommendation::KeepNiche],
            'remove candidate' => [44, 34, [['code' => 'weak_gift_fit', 'context' => ['substantive' => true]], ['code' => 'low_catalog_value']], false, CurationRecommendation::RemoveCandidate],
            'replace candidate' => [55, 49, [['code' => 'weak_gift_fit'], ['code' => 'low_catalog_value'], ['code' => 'concept_oversaturated']], false, CurationRecommendation::ReplaceCandidate],
            'missing evidence reviews' => [90, 90, [['code' => 'missing_commerce_evidence']], false, CurationRecommendation::Review],
            'fallback review' => [64, 59, [], false, CurationRecommendation::Review],
        ];
    }

    public function test_roles_are_resolved_by_laravel_after_context(): void
    {
        $resolver = app(ResolveCatalogRoleAction::class);
        $components = [
            'uniqueness' => ['score' => 12],
            'value_for_money' => ['score' => 12],
        ];

        $this->assertSame(CatalogRole::BestOverall, $resolver->execute(80, 70, [], $components, $this->context('1000-2499')));
        $this->assertSame(CatalogRole::BestValue, $resolver->execute(70, 65, [], $components, $this->context('1000-2499')));
        $this->assertSame(CatalogRole::PremiumPick, $resolver->execute(70, 55, [], ['value_for_money' => ['score' => 5]], $this->context('5000-plus')));
        $this->assertSame(CatalogRole::NichePick, $resolver->execute(60, 75, ['niche_signals' => ['Collector use']], [], $this->context('1000-2499')));
        $this->assertSame(CatalogRole::UniquePick, $resolver->execute(70, 65, [], ['uniqueness' => ['score' => 12]], $this->context('1000-2499')));
        $this->assertSame(CatalogRole::Undifferentiated, $resolver->execute(50, 40, [], [], $this->context(null)));
    }

    public function test_medium_confidence_does_not_create_low_confidence_issue(): void
    {
        $issues = app(BuildCurationIssuesAction::class)->execute(
            $this->evidence(),
            [],
            [],
            65,
            50,
            'medium',
            [
                'value_for_money' => ['score' => 10, 'evidence_status' => 'supported'],
                'product_vendor_confidence' => ['score' => 10],
            ],
            ['why_this_gift' => 'Useful for a tea drinker who enjoys a calm daily brewing ritual.'],
            [
                'snapshot' => ['signals' => []],
                'peer_counts' => [],
                'peer_product_ids' => [],
            ],
        );

        $this->assertNotContains('low_ai_confidence', array_column($issues, 'code'));
    }

    public function test_generic_why_this_gift_is_flagged_deterministically(): void
    {
        $issues = app(BuildCurationIssuesAction::class)->execute(
            $this->evidence(),
            [],
            [],
            80,
            70,
            'high',
            [
                'value_for_money' => ['score' => 10, 'evidence_status' => 'supported'],
                'product_vendor_confidence' => ['score' => 10],
            ],
            ['why_this_gift' => 'Perfect gift.'],
            [
                'snapshot' => ['signals' => []],
                'peer_counts' => [],
                'peer_product_ids' => [],
            ],
        );

        $this->assertContains('weak_why_this_gift', array_column($issues, 'code'));
    }

    public function test_issue_codes_cover_the_approved_v1_contract(): void
    {
        $values = array_column(CurationIssueCode::cases(), 'value');

        foreach ([
            'weak_gift_fit',
            'low_ai_confidence',
            'relationship_overclassification',
            'occasion_overclassification',
            'interest_mismatch',
            'gift_type_mismatch',
            'taxonomy_conflict',
            'taxonomy_fit_advisory',
            'hard_taxonomy_conflict',
            'unresolved_taxonomy_label',
            'missing_current_assignment_evaluation',
            'missing_primary_category',
            'weak_why_this_gift',
            'concept_oversaturated',
            'possible_concept_duplicate',
            'low_catalog_value',
            'weak_value_for_money',
            'weak_product_confidence',
            'missing_commerce_evidence',
        ] as $code) {
            $this->assertContains($code, $values);
        }
    }

    public function test_human_review_baselines_and_candidate_amendments_are_exact(): void
    {
        $amend = app(AmendCurationReviewAction::class);

        $this->assertFalse($amend->execute(CurationRecommendation::Keep, [], 65, 50)['requires_human_review']);
        $this->assertTrue($amend->execute(CurationRecommendation::Review, [], 64, 50)['requires_human_review']);
        $this->assertTrue($amend->execute(CurationRecommendation::Review, [], 65, 49)['requires_human_review']);

        $candidate = $amend->execute(
            CurationRecommendation::ReplaceCandidate,
            [['code' => 'concept_oversaturated']],
            60,
            40,
        );
        $this->assertSame(CurationRecommendation::ReplaceCandidate, $candidate['recommendation']);
        $this->assertTrue($candidate['requires_human_review']);
    }

    public function test_prompt_schema_excludes_laravel_derived_fields(): void
    {
        $schema = app(ProductCurationSemanticPrompt::class)->jsonSchema();
        $properties = $schema['properties'];

        $this->assertArrayNotHasKey('catalog_role', $properties);
        $this->assertArrayNotHasKey('product_vendor_confidence', $properties['gift_components']['properties']);
        $this->assertSame(['integer', 'null'], $properties['gift_components']['properties']['value_for_money']['type']);
        $this->assertSame(
            'boolean',
            $properties['current_taxonomy_evaluations']['properties']['relationships']['items']['properties']['misleading_on_targeted_landing_page']['type'],
        );
        $this->assertContains(
            'reason',
            $properties['taxonomy_suggestions']['properties']['relationships']['items']['required'],
        );
        $this->assertSame(240, $properties['why_this_gift']['maxLength']);
        $this->assertArrayHasKey('strengths', $properties);
        $this->assertArrayHasKey('concept_key_candidate', $properties);
        $this->assertArrayHasKey('concept_label_candidate', $properties);
        $this->assertSame(['strong', 'medium', 'weak', 'none'], $properties['differentiation_strength']['enum']);
        $this->assertSame(['strong', 'medium', 'weak', 'none'], $properties['niche_contribution']['enum']);
    }

    public function test_prompt_defines_score_value_concept_and_fit_anchors(): void
    {
        $instructions = app(ProductCurationSemanticPrompt::class)->systemInstructions();

        $this->assertStringContainsString('17-20 very likely appreciated', $instructions);
        $this->assertStringContainsString('value_for_money /15', $instructions);
        $this->assertStringContainsString('Return null only when evidence is genuinely insufficient', $instructions);
        $this->assertStringContainsString('underlying gift idea, not an exact SKU feature bundle', $instructions);
        $this->assertStringContainsString('strong: natural primary recommendation', $instructions);
        $this->assertStringContainsString('misleading_on_targeted_landing_page', $instructions);
    }

    private function evidence(): ProductCurationEvidence
    {
        return new ProductCurationEvidence(
            productId: 10,
            name: 'Tea Infuser',
            shortDescription: 'Reusable tea infuser.',
            description: null,
            brand: null,
            status: 'draft',
            priceAmount: null,
            priceCurrency: 'INR',
            compareAtAmount: null,
            rating: null,
            reviewCount: null,
            taxonomy: [
                'categories' => [],
                'relationships' => [['id' => 1, 'name' => 'Father', 'slug' => 'father']],
                'occasions' => [],
                'interests' => [],
                'gift_types' => [],
                'recipient_types' => [],
                'professions' => [],
            ],
            offers: [],
            images: [],
            provenance: [],
        );
    }

    private function evidenceForDimension(string $dimension): ProductCurationEvidence
    {
        $taxonomy = [
            'categories' => [],
            'relationships' => [],
            'occasions' => [],
            'interests' => [],
            'gift_types' => [],
            'recipient_types' => [],
            'professions' => [],
        ];
        $taxonomy[$dimension] = [[
            'id' => 11,
            'name' => 'Assigned taxonomy',
            'slug' => 'assigned-taxonomy',
        ]];

        return new ProductCurationEvidence(
            productId: 10,
            name: 'Calibrated Gift',
            shortDescription: 'A product used to calibrate taxonomy review.',
            description: null,
            brand: null,
            status: 'draft',
            priceAmount: '1000.00',
            priceCurrency: 'INR',
            compareAtAmount: null,
            rating: null,
            reviewCount: null,
            taxonomy: $taxonomy,
            offers: [],
            images: [],
            provenance: [],
        );
    }

    /**
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function emptyTaxonomySemantic(): array
    {
        return [
            'current_taxonomy_evaluations' => [
                'relationships' => [],
                'occasions' => [],
                'interests' => [],
                'gift_types' => [],
            ],
            'taxonomy_suggestions' => [
                'relationships' => [],
                'occasions' => [],
                'interests' => [],
                'gift_types' => [],
            ],
        ];
    }

    private function catalog(): CommercialTaxonomyCatalog
    {
        return new CommercialTaxonomyCatalog(
            categories: [],
            occasions: [],
            relationships: [
                ['id' => 1, 'name' => 'Father', 'slug' => 'father', 'description' => null],
                ['id' => 2, 'name' => 'Mother', 'slug' => 'mother', 'description' => null],
            ],
            recipientTypes: [],
            recipientGenders: [],
            interests: [],
            professions: [],
            giftTypes: [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'gift_components' => [
                'recipient_desirability' => 16,
                'thoughtfulness_emotional_potential' => 12,
                'uniqueness' => 12,
                'value_for_money' => null,
                'visual_gifting_appeal' => 8,
                'practical_usefulness' => 8,
                'social_media_shareability' => 3,
            ],
            'gift_intents' => ['sentimental', 'practical'],
            'current_taxonomy_evaluations' => [
                'relationships' => [[
                    'name' => 'Father',
                    'slug' => 'father',
                    'strength' => 'strong',
                    'reason' => 'A relevant practical gift.',
                    'misleading_on_targeted_landing_page' => false,
                ]],
                'occasions' => [],
                'interests' => [],
                'gift_types' => [],
            ],
            'taxonomy_suggestions' => [
                'relationships' => [],
                'occasions' => [],
                'interests' => [],
                'gift_types' => [],
            ],
            'concept_key_candidate' => 'Tea Infuser!',
            'concept_label_candidate' => 'Tea Infuser',
            'why_this_gift' => 'Useful for a tea drinker who enjoys a calm daily brewing ritual.',
            'confidence' => 'high',
            'strengths' => ['Reusable', 'Simple daily ritual'],
            'differentiation_signals' => ['Reusable'],
            'differentiation_strength' => 'medium',
            'niche_signals' => [],
            'niche_contribution' => 'none',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function context(?string $priceBand): array
    {
        return ['snapshot' => ['price_band' => $priceBand]];
    }
}
