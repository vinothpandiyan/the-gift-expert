<?php

namespace Tests\Feature\CatalogCuration;

use App\Actions\CatalogCuration\BuildHumanCurationReviewAction;
use App\Actions\CatalogCuration\QueryHumanCurationQueueAction;
use App\Actions\CatalogCuration\RecordProductCurationDecisionAction;
use App\Actions\CatalogCuration\ResolveCurationReviewProgressAction;
use App\Actions\CatalogCuration\ResolveP3QualitySubgroupAction;
use App\CatalogCuration\HumanCurationQueueCriteria;
use App\Enums\CurationAiConfidence;
use App\Enums\CurationRecommendation;
use App\Enums\P3QualitySubgroup;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationDecisionReasonCode;
use App\Enums\ProductCurationPriority;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsHumanCurationFixtures;
use Tests\TestCase;

class P3QualityCurationTest extends TestCase
{
    use BuildsHumanCurationFixtures;
    use RefreshDatabase;

    public function test_p3_subgroups_are_assigned_deterministically_without_a_new_score(): void
    {
        $run = $this->acceptedCurationRun();
        $resolve = app(ResolveP3QualitySubgroupAction::class);

        $lowGift = $this->curatedAudit($run, Product::factory()->create(['name' => 'Low Gift']), [
            'gift_score' => 40,
            'catalog_value_score' => 20,
            'ai_confidence' => CurationAiConfidence::Low,
            'issues' => [[
                'code' => 'missing_commerce_evidence',
                'severity' => 'warning',
                'message' => 'Price is missing.',
                'context' => [],
            ]],
        ]);
        $lowCatalog = $this->curatedAudit($run, Product::factory()->create(['name' => 'Low Catalog']), [
            'gift_score' => 70,
            'catalog_value_score' => 22,
        ]);
        $weakEvidence = $this->curatedAudit($run, Product::factory()->create(['name' => 'Weak Evidence']), [
            'gift_score' => 70,
            'catalog_value_score' => 55,
            'issues' => [[
                'code' => 'weak_product_confidence',
                'severity' => 'warning',
                'message' => 'Vendor confidence is weak.',
                'context' => [],
            ]],
        ]);
        $lowConfidence = $this->curatedAudit($run, Product::factory()->create(['name' => 'Low Confidence']), [
            'gift_score' => 70,
            'catalog_value_score' => 55,
            'ai_confidence' => CurationAiConfidence::Low,
        ]);
        $remaining = $this->curatedAudit($run, Product::factory()->create(['name' => 'Remaining Quality']), [
            'gift_score' => 70,
            'catalog_value_score' => 55,
            'ai_confidence' => CurationAiConfidence::Medium,
            'issues' => [[
                'code' => 'weak_gift_fit',
                'severity' => 'warning',
                'message' => 'Gift fit is borderline.',
                'context' => [],
            ]],
        ]);
        $p1 = $this->curatedAudit($run, Product::factory()->create(['name' => 'Redundant Wallet']), [
            'gift_score' => 40,
            'catalog_value_score' => 12,
            'concept_key' => 'wallet',
            'peer_counts' => ['concept' => 2],
            'differentiation_factor' => 4,
            'catalog_context_snapshot' => ['differentiation_strength' => 'weak'],
        ]);
        $p4 = $this->curatedAudit($run, Product::factory()->create(['name' => 'Advisory Gift']), [
            'requires_human_review' => false,
            'recommendation' => CurationRecommendation::Keep,
            'gift_score' => 84,
            'catalog_value_score' => 72,
        ]);

        $this->assertSame(P3QualitySubgroup::LowGiftScore, $resolve->execute($lowGift));
        $this->assertSame(P3QualitySubgroup::LowCatalogValue, $resolve->execute($lowCatalog));
        $this->assertSame(P3QualitySubgroup::WeakEvidence, $resolve->execute($weakEvidence));
        $this->assertSame(P3QualitySubgroup::LowConfidence, $resolve->execute($lowConfidence));
        $this->assertSame(P3QualitySubgroup::RemainingQuality, $resolve->execute($remaining));
        $this->assertNull($resolve->execute($p1));
        $this->assertNull($resolve->execute($p4));

        $queue = app(QueryHumanCurationQueueAction::class);

        $this->assertSame([
            $lowGift->product_id,
            $lowCatalog->product_id,
            $weakEvidence->product_id,
            $lowConfidence->product_id,
            $remaining->product_id,
        ], $queue->productIds(new HumanCurationQueueCriteria(view: 'p3')));
        $this->assertSame([$lowGift->product_id], $queue->productIds(new HumanCurationQueueCriteria(view: 'p3_a')));
        $this->assertSame([$lowCatalog->product_id], $queue->productIds(new HumanCurationQueueCriteria(view: 'p3_b')));
        $this->assertSame([$weakEvidence->product_id], $queue->productIds(new HumanCurationQueueCriteria(view: 'p3_c')));
        $this->assertSame([$lowConfidence->product_id], $queue->productIds(new HumanCurationQueueCriteria(view: 'p3_d')));
        $this->assertSame([$remaining->product_id], $queue->productIds(new HumanCurationQueueCriteria(view: 'p3_e')));
        $this->assertNotContains($p4->product_id, $queue->productIds(new HumanCurationQueueCriteria(view: 'p3')));
        $this->assertContains($p4->product_id, $queue->productIds(new HumanCurationQueueCriteria(view: 'p4')));
    }

    public function test_resolved_p0_p1_and_p2_products_do_not_reenter_p3(): void
    {
        $run = $this->acceptedCurationRun();
        $user = User::factory()->create();

        $p0 = $this->curatedAudit($run, Product::factory()->create(['name' => 'Integrity Case']), [
            'evidence_snapshot' => ['product_id' => 999, 'name' => ''],
            'issues' => [[
                'code' => 'semantic_response_invalid',
                'severity' => 'blocking',
                'message' => 'Malformed semantic response.',
                'context' => [],
            ]],
        ]);
        $p1 = $this->curatedAudit($run, Product::factory()->create(['name' => 'Wallet Duplicate']), [
            'gift_score' => 58,
            'catalog_value_score' => 18,
            'concept_key' => 'wallet',
            'peer_counts' => ['concept' => 2],
            'differentiation_factor' => 4,
            'catalog_context_snapshot' => ['differentiation_strength' => 'weak'],
        ]);
        $p2 = $this->curatedAudit($run, Product::factory()->create(['name' => 'Taxonomy Case']), [
            'gift_score' => 60,
            'catalog_value_score' => 48,
            'taxonomy_differences' => [[
                'dimension' => 'relationships',
                'severity' => 'material',
                'forces_human_review' => true,
                'taxonomy' => ['name' => 'Husband'],
                'reason' => 'Misleading relationship.',
            ]],
        ]);
        $p3 = $this->curatedAudit($run, Product::factory()->create(['name' => 'Quality Case']), [
            'gift_score' => 60,
            'catalog_value_score' => 48,
        ]);

        $record = app(RecordProductCurationDecisionAction::class);
        $record->execute(
            product: $p0->product,
            audit: $p0,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [ProductCurationDecisionReasonCode::StrongPracticalValue],
            reasonNotes: 'Usable after integrity diagnosis.',
            user: $user,
        );
        $record->execute(
            product: $p1->product,
            audit: $p1,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [ProductCurationDecisionReasonCode::UniqueConcept],
            reasonNotes: 'Distinct wallet role.',
            user: $user,
        );
        $record->execute(
            product: $p2->product,
            audit: $p2,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [ProductCurationDecisionReasonCode::FillsCatalogGap],
            reasonNotes: 'Taxonomy finding was a false positive.',
            user: $user,
        );

        $queue = app(QueryHumanCurationQueueAction::class);

        $this->assertSame([$p3->product_id], $queue->productIds(new HumanCurationQueueCriteria(view: 'p3')));
        $this->assertNotContains($p0->product_id, $queue->productIds(new HumanCurationQueueCriteria(view: 'p3')));
        $this->assertNotContains($p1->product_id, $queue->productIds(new HumanCurationQueueCriteria(view: 'p3')));
        $this->assertNotContains($p2->product_id, $queue->productIds(new HumanCurationQueueCriteria(view: 'p3')));
        $this->assertSame([$p3->product_id], $queue->productIds(new HumanCurationQueueCriteria(view: 'needs_review')));
    }

    public function test_keep_family_requires_a_retention_reason(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);
        $user = User::factory()->create();

        try {
            app(RecordProductCurationDecisionAction::class)->execute(
                product: $audit->product,
                audit: $audit,
                decision: ProductCurationDecision::Keep,
                reasonCodes: [ProductCurationDecisionReasonCode::AuditAnomaly],
                reasonNotes: 'Looks fine.',
                user: $user,
            );
            $this->fail('Expected KEEP without a retention reason to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason_codes', $exception->errors());
        }

        $decision = app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [
                ProductCurationDecisionReasonCode::StrongGift,
                ProductCurationDecisionReasonCode::AuditAnomaly,
            ],
            reasonNotes: 'Strong everyday gift despite an audit anomaly.',
            user: $user,
        );

        $this->assertSame(ProductCurationDecision::Keep, $decision->decision);
        $this->assertSame(ProductStatus::Draft, $audit->product->fresh()->status);
    }

    public function test_deactivate_and_remove_candidate_require_a_negative_reason(): void
    {
        $run = $this->acceptedCurationRun();
        $deactivate = $this->curatedAudit($run, Product::factory()->create(['name' => 'Deactivate Gift']));
        $remove = $this->curatedAudit($run, Product::factory()->create(['name' => 'Remove Gift']));
        $user = User::factory()->create();

        try {
            app(RecordProductCurationDecisionAction::class)->execute(
                product: $deactivate->product,
                audit: $deactivate,
                decision: ProductCurationDecision::Deactivate,
                reasonCodes: [ProductCurationDecisionReasonCode::Other],
                reasonNotes: 'Not sure this belongs.',
                user: $user,
            );
            $this->fail('Expected DEACTIVATE without a negative reason to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason_codes', $exception->errors());
        }

        app(RecordProductCurationDecisionAction::class)->execute(
            product: $deactivate->product,
            audit: $deactivate,
            decision: ProductCurationDecision::Deactivate,
            reasonCodes: [ProductCurationDecisionReasonCode::GenericProduct],
            reasonNotes: 'Generic commodity with no catalog role.',
            user: $user,
        );

        $removeDecision = app(RecordProductCurationDecisionAction::class)->execute(
            product: $remove->product,
            audit: $remove,
            decision: ProductCurationDecision::RemoveCandidate,
            reasonCodes: [ProductCurationDecisionReasonCode::WeakDifferentiation],
            reasonNotes: 'Near-duplicate of a stronger gift, but leave live for now.',
            user: $user,
        );

        $this->assertSame(ProductStatus::Archived, $deactivate->product->fresh()->status);
        $this->assertSame(ProductStatus::Draft, $remove->product->fresh()->status);
        $this->assertSame(ProductCurationDecision::RemoveCandidate, $removeDecision->decision);
        $this->assertTrue($removeDecision->isHumanReviewed());
        $this->assertNotContains(
            $remove->product_id,
            app(QueryHumanCurationQueueAction::class)->productIds(new HumanCurationQueueCriteria(view: 'p3')),
        );
    }

    public function test_feature_density_is_observed_and_does_not_block(): void
    {
        $run = $this->acceptedCurationRun();
        $user = User::factory()->create();
        $record = app(RecordProductCurationDecisionAction::class);

        $first = $this->curatedAudit($run, Product::factory()->create(['name' => 'Featured One']), [
            'gift_score' => 70,
            'catalog_value_score' => 55,
        ]);
        $second = $this->curatedAudit($run, Product::factory()->create(['name' => 'Featured Two']), [
            'gift_score' => 72,
            'catalog_value_score' => 56,
        ]);
        $third = $this->curatedAudit($run, Product::factory()->create(['name' => 'Kept Three']), [
            'gift_score' => 68,
            'catalog_value_score' => 54,
        ]);

        $record->execute(
            product: $first->product,
            audit: $first,
            decision: ProductCurationDecision::Feature,
            reasonCodes: [ProductCurationDecisionReasonCode::StrongGift],
            reasonNotes: 'Standout recommendation.',
            user: $user,
        );
        $record->execute(
            product: $second->product,
            audit: $second,
            decision: ProductCurationDecision::Feature,
            reasonCodes: [ProductCurationDecisionReasonCode::StrongEmotionalValue],
            reasonNotes: 'Highly giftable.',
            user: $user,
        );

        $progress = app(ResolveCurationReviewProgressAction::class)->execute($run);

        $this->assertSame(2, $progress->featureCount);
        $this->assertSame(2, $progress->keepFamilyCount);
        $this->assertTrue($progress->featureDensityWarning);

        $record->execute(
            product: $third->product,
            audit: $third,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [ProductCurationDecisionReasonCode::FillsCatalogGap],
            reasonNotes: 'Useful catalog filler.',
            user: $user,
        );

        $later = app(ResolveCurationReviewProgressAction::class)->execute($run);

        $this->assertSame(2, $later->featureCount);
        $this->assertSame(3, $later->keepFamilyCount);
        $this->assertFalse($later->featureDensityWarning);
        $this->assertSame(ProductStatus::Draft, $first->product->fresh()->status);
    }

    public function test_p3_review_case_exposes_cohort_and_strongest_fits(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run, Product::factory()->create(['name' => 'Quality Review Gift']), [
            'gift_score' => 58,
            'catalog_value_score' => 52,
            'gift_intents' => ['sentimental'],
            'strongest_fits' => [
                'relationships' => ['name' => 'Wife', 'strength' => 'strong'],
                'occasions' => ['name' => 'Anniversary', 'strength' => 'medium'],
                'interests' => ['name' => 'Home Decor', 'strength' => 'medium'],
                'gift_types' => ['name' => 'Personalized', 'strength' => 'strong'],
            ],
        ]);

        $case = app(BuildHumanCurationReviewAction::class)->execute($audit->product->fresh());

        $this->assertNotNull($case);
        $this->assertSame(ProductCurationPriority::P3, $case->priority);
        $this->assertSame(P3QualitySubgroup::LowGiftScore, $case->qualitySubgroup);
        $this->assertSame('Wife', $case->strongestFits['relationships']['name']);
        $this->assertSame('Anniversary', $case->strongestFits['occasions']['name']);
    }

    public function test_p3_counts_are_resolved_from_the_queue_not_hardcoded(): void
    {
        $run = $this->acceptedCurationRun();
        $this->curatedAudit($run, Product::factory()->create(['name' => 'Quality A']), [
            'gift_score' => 58,
            'catalog_value_score' => 52,
        ]);
        $this->curatedAudit($run, Product::factory()->create(['name' => 'Quality B']), [
            'gift_score' => 70,
            'catalog_value_score' => 40,
        ]);

        $progress = app(ResolveCurationReviewProgressAction::class)->execute($run);
        $counts = app(QueryHumanCurationQueueAction::class)->viewCounts($run);

        $this->assertSame(2, $progress->countForPriority(ProductCurationPriority::P3));
        $this->assertSame(2, $counts['p3']);
        $this->assertSame(1, $counts['p3_a']);
        $this->assertSame(1, $counts['p3_b']);
        $this->assertSame(0, $counts['p4']);
        $this->assertNotSame(147, $counts['p3']);
    }
}
