<?php

namespace Tests\Unit\Actions\CuratedCatalog;

use App\Actions\CuratedCatalog\ResolveCuratedClassificationDecisionAction;
use App\CommercialSourcing\ValidatedProductTaxonomyClassification;
use App\CuratedCatalog\CuratedClassificationConfidence;
use App\CuratedCatalog\CuratedTaxonomyGap;
use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyClassificationWarningCode;
use App\Enums\TaxonomyDimension;
use App\Enums\TaxonomyGapSeverity;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\TaxonomyApplicabilityRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveCuratedClassificationDecisionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_category_confidence_auto_accepts(): void
    {
        $decision = $this->decide($this->taxonomy(1), $this->confidence(0.95));

        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $decision->status);
        $this->assertSame([], $decision->reviewReasons);
    }

    public function test_low_category_confidence_goes_to_review(): void
    {
        config(['curated_catalog.taxonomy_classification.thresholds.primary_category_auto_accept' => 0.85]);

        $decision = $this->decide($this->taxonomy(1), $this->confidence(0.70));

        $this->assertSame(TaxonomyClassificationStatus::Review, $decision->status);
        $this->assertContains(TaxonomyClassificationWarningCode::LowPrimaryCategoryConfidence->value, $decision->reviewReasons);
    }

    public function test_missing_gift_type_does_not_penalize(): void
    {
        $decision = $this->decide(
            $this->taxonomy(1, giftTypeIds: []),
            $this->confidence(0.95, giftTypes: 0.10),
        );

        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $decision->status);
    }

    public function test_valid_gift_type_with_high_confidence_is_kept(): void
    {
        $decision = $this->decide(
            $this->taxonomy(1, giftTypeIds: [9]),
            $this->confidence(0.95, giftTypes: 0.91),
        );

        $this->assertSame([9], $decision->proposal?->taxonomy->giftTypeIds);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $decision->status);
    }

    public function test_low_confidence_optional_interest_is_dropped_without_review(): void
    {
        $decision = $this->decide(
            $this->taxonomy(1, interestIds: [7]),
            $this->confidence(0.95, interests: 0.40),
        );

        $this->assertSame([], $decision->proposal?->taxonomy->interestIds);
        $this->assertContains(TaxonomyClassificationWarningCode::AiOptionalTaxonomyDropped->value, $decision->warnings);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $decision->status);
    }

    public function test_advisory_taxonomy_gap_with_strong_category_auto_accepts(): void
    {
        $confidence = $this->confidence(0.98);
        $decision = $this->decide(
            $this->taxonomy(1),
            $confidence,
            new CuratedTaxonomyGap(true, 'Diecast Models', 'More specific leaf could help later', TaxonomyGapSeverity::Advisory),
        );

        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $decision->status);
        $this->assertSame([], $decision->reviewReasons);
        $this->assertContains(TaxonomyClassificationWarningCode::TaxonomyGapAdvisory->value, $decision->warnings);
        $this->assertNotContains(TaxonomyClassificationWarningCode::TaxonomyGapBlocking->value, $decision->warnings);
        $this->assertTrue($decision->taxonomyGap->isAdvisory());
        $this->assertSame(0.98, $decision->proposal?->confidence->primaryCategory);
        $this->assertSame('Diecast Models', $decision->taxonomyGap->suggestedConcept);
    }

    public function test_omitted_gap_severity_with_valid_primary_is_advisory(): void
    {
        $decision = $this->decide(
            $this->taxonomy(1),
            $this->confidence(0.95),
            new CuratedTaxonomyGap(true, 'Photo Albums & Scrapbooks', 'Stationery & Office is still valid'),
        );

        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $decision->status);
        $this->assertContains(TaxonomyClassificationWarningCode::TaxonomyGapAdvisory->value, $decision->warnings);
        $this->assertSame([], $decision->reviewReasons);
        $this->assertTrue($decision->taxonomyGap->isAdvisory());
    }

    public function test_blocking_taxonomy_gap_goes_to_review(): void
    {
        $decision = $this->decide(
            $this->taxonomy(1),
            $this->confidence(0.95),
            new CuratedTaxonomyGap(true, 'Unknown product family', 'No honest merchandising category', TaxonomyGapSeverity::Blocking),
        );

        $this->assertSame(TaxonomyClassificationStatus::Review, $decision->status);
        $this->assertContains(TaxonomyClassificationWarningCode::TaxonomyGapBlocking->value, $decision->reviewReasons);
        $this->assertTrue($decision->taxonomyGap->isBlocking());
        $this->assertSame('Unknown product family', $decision->taxonomyGap->suggestedConcept);
    }

    public function test_missing_primary_fails_and_persists_blocking_gap(): void
    {
        $decision = $this->decide(
            $this->taxonomy(null),
            $this->confidence(0.40),
            new CuratedTaxonomyGap(true, 'Hampers', 'Identity is too ambiguous for a merchandising category'),
        );

        $this->assertSame(TaxonomyClassificationStatus::Failed, $decision->status);
        $this->assertContains(TaxonomyClassificationWarningCode::MissingPrimaryCategory->value, $decision->reviewReasons);
        $this->assertContains(TaxonomyClassificationWarningCode::TaxonomyGapBlocking->value, $decision->warnings);
        $this->assertTrue($decision->taxonomyGap->isBlocking());
        $this->assertSame('Hampers', $decision->taxonomyGap->suggestedConcept);
        $this->assertSame(0.40, $decision->proposal?->confidence->primaryCategory);
        $this->assertNull($decision->proposal?->taxonomy->primaryCategoryId);
    }

    public function test_advisory_gap_does_not_mask_low_category_confidence(): void
    {
        config(['curated_catalog.taxonomy_classification.thresholds.primary_category_auto_accept' => 0.85]);

        $decision = $this->decide(
            $this->taxonomy(1),
            $this->confidence(0.70),
            new CuratedTaxonomyGap(true, 'Home Decor & Showpieces', 'Parent remains valid', TaxonomyGapSeverity::Advisory),
        );

        $this->assertSame(TaxonomyClassificationStatus::Review, $decision->status);
        $this->assertContains(TaxonomyClassificationWarningCode::LowPrimaryCategoryConfidence->value, $decision->reviewReasons);
        $this->assertNotContains(TaxonomyClassificationWarningCode::TaxonomyGapAdvisory->value, $decision->reviewReasons);
        $this->assertContains(TaxonomyClassificationWarningCode::TaxonomyGapAdvisory->value, $decision->warnings);
    }

    public function test_trusted_hint_omitted_by_ai_is_added(): void
    {
        $husband = $this->relationship('Husband');

        $decision = $this->decide(
            $this->taxonomy(1, relationshipIds: []),
            $this->confidence(0.95),
            sourceRelationshipHintIds: [$husband->id],
        );

        $this->assertSame([$husband->id], $decision->proposal?->taxonomy->relationshipIds);
        $this->assertContains(TaxonomyClassificationWarningCode::TrustedHintAdded->value, $decision->warnings);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $decision->status);
    }

    public function test_ai_added_husband_raksha_conflict_drops_occasion(): void
    {
        $husband = $this->relationship('Husband');
        $brother = $this->relationship('Brother');
        $raksha = $this->occasion('Raksha Bandhan');
        $this->allowRaksha($raksha, $brother);

        $decision = $this->decide(
            $this->taxonomy(1, relationshipIds: [$husband->id], occasionIds: [$raksha->id]),
            $this->confidence(0.95),
            sourceRelationshipHintIds: [],
        );

        $this->assertSame([$husband->id], $decision->proposal?->taxonomy->relationshipIds);
        $this->assertSame([], $decision->proposal?->taxonomy->occasionIds);
        $this->assertContains(TaxonomyClassificationWarningCode::AiSecondaryAssignmentRemoved->value, $decision->warnings);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $decision->status);
    }

    public function test_trusted_husband_with_raksha_goes_to_review(): void
    {
        $husband = $this->relationship('Husband');
        $brother = $this->relationship('Brother');
        $raksha = $this->occasion('Raksha Bandhan');
        $this->allowRaksha($raksha, $brother);

        $decision = $this->decide(
            $this->taxonomy(1, relationshipIds: [$husband->id], occasionIds: [$raksha->id]),
            $this->confidence(0.95),
            sourceRelationshipHintIds: [$husband->id],
        );

        $this->assertSame(TaxonomyClassificationStatus::Review, $decision->status);
        $this->assertContains(TaxonomyClassificationWarningCode::TrustedSourceSemanticConflict->value, $decision->reviewReasons);
        $this->assertContains($husband->id, $decision->proposal?->taxonomy->relationshipIds ?? []);
        $this->assertContains($raksha->id, $decision->proposal?->taxonomy->occasionIds ?? []);
    }

    public function test_advisory_gap_does_not_change_trusted_source_conflict_review(): void
    {
        $husband = $this->relationship('Husband');
        $brother = $this->relationship('Brother');
        $raksha = $this->occasion('Raksha Bandhan');
        $this->allowRaksha($raksha, $brother);

        $decision = $this->decide(
            $this->taxonomy(1, relationshipIds: [$husband->id], occasionIds: [$raksha->id]),
            $this->confidence(0.95),
            new CuratedTaxonomyGap(true, 'Home Decor & Showpieces', 'Parent remains valid', TaxonomyGapSeverity::Advisory),
            [$husband->id],
        );

        $this->assertSame(TaxonomyClassificationStatus::Review, $decision->status);
        $this->assertContains(TaxonomyClassificationWarningCode::TrustedSourceSemanticConflict->value, $decision->reviewReasons);
        $this->assertNotContains(TaxonomyClassificationWarningCode::TaxonomyGapAdvisory->value, $decision->reviewReasons);
        $this->assertContains(TaxonomyClassificationWarningCode::TaxonomyGapAdvisory->value, $decision->warnings);
    }

    /**
     * @param  list<int>  $sourceRelationshipHintIds
     */
    private function decide(
        ValidatedProductTaxonomyClassification $taxonomy,
        CuratedClassificationConfidence $confidence,
        ?CuratedTaxonomyGap $gap = null,
        array $sourceRelationshipHintIds = [],
    ) {
        return app(ResolveCuratedClassificationDecisionAction::class)->execute(
            $taxonomy,
            $confidence,
            $gap ?? new CuratedTaxonomyGap,
            $sourceRelationshipHintIds,
        );
    }

    /**
     * @param  list<int>  $relationshipIds
     * @param  list<int>  $occasionIds
     * @param  list<int>  $interestIds
     * @param  list<int>  $giftTypeIds
     */
    private function taxonomy(
        ?int $primary,
        array $relationshipIds = [],
        array $occasionIds = [],
        array $interestIds = [],
        array $giftTypeIds = [],
    ): ValidatedProductTaxonomyClassification {
        return new ValidatedProductTaxonomyClassification(
            primaryCategoryId: $primary,
            categoryIds: $primary === null ? [] : [$primary],
            occasionIds: $occasionIds,
            relationshipIds: $relationshipIds,
            recipientTypeIds: [],
            interestIds: $interestIds,
            professionIds: [],
            giftTypeIds: $giftTypeIds,
            exceptionCodes: $primary === null ? ['missing_primary_category'] : [],
            rejectedIds: [],
        );
    }

    private function confidence(float $primary, float $giftTypes = 0.95, float $interests = 0.90): CuratedClassificationConfidence
    {
        return new CuratedClassificationConfidence(
            primaryCategory: $primary,
            relationships: 0.90,
            recipientTypes: 0.90,
            occasions: 0.90,
            interests: $interests,
            professions: 0.95,
            giftTypes: $giftTypes,
        );
    }

    private function allowRaksha(Occasion $raksha, Relationship $brother): void
    {
        $sister = $this->relationship('Sister');

        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => TaxonomyDimension::Occasion,
            'source_id' => $raksha->id,
            'target_dimension' => TaxonomyDimension::Relationship,
            'target_id' => $brother->id,
            'effect' => TaxonomyApplicabilityEffect::Allow,
            'reason' => 'test',
            'is_active' => true,
        ]);
        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => TaxonomyDimension::Occasion,
            'source_id' => $raksha->id,
            'target_dimension' => TaxonomyDimension::Relationship,
            'target_id' => $sister->id,
            'effect' => TaxonomyApplicabilityEffect::Allow,
            'reason' => 'test',
            'is_active' => true,
        ]);
    }

    private function relationship(string $name): Relationship
    {
        return Relationship::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function occasion(string $name): Occasion
    {
        return Occasion::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }
}
