<?php

namespace Tests\Feature\CatalogCuration;

use App\Actions\CatalogCuration\ApplyHumanCurationReclassificationAction;
use App\Actions\CatalogCuration\QueryHumanCurationQueueAction;
use App\Actions\CatalogCuration\RecordProductCurationDecisionAction;
use App\Actions\CatalogCuration\ResolveAcceptedCurationAuditRunAction;
use App\Actions\CatalogCuration\ResolveCurationReviewProgressAction;
use App\Actions\CatalogCuration\ResolveProductConceptPeersAction;
use App\Actions\CatalogCuration\ResolveProductCurationPriorityAction;
use App\CatalogCuration\HumanCurationQueueCriteria;
use App\Enums\CurationAiConfidence;
use App\Enums\CurationRecommendation;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationDecisionReasonCode;
use App\Enums\ProductCurationPriority;
use App\Enums\ProductCurationRunStatus;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsHumanCurationFixtures;
use Tests\TestCase;

class HumanCurationQueueTest extends TestCase
{
    use BuildsHumanCurationFixtures;
    use RefreshDatabase;

    public function test_it_resolves_the_latest_accepted_full_catalog_run(): void
    {
        $older = $this->acceptedCurationRun(['finished_at' => now()->subHour()]);
        $filtered = $this->acceptedCurationRun([
            'finished_at' => now()->subMinute(),
            'options' => ['product' => [1], 'only_missing' => false, 'limit' => null],
        ]);
        $accepted = $this->acceptedCurationRun(['finished_at' => now()]);
        $this->acceptedCurationRun([
            'status' => ProductCurationRunStatus::Running,
            'finished_at' => null,
        ]);

        $resolved = app(ResolveAcceptedCurationAuditRunAction::class)->execute();

        $this->assertNotNull($resolved);
        $this->assertSame($accepted->id, $resolved->id);
        $this->assertNotSame($older->id, $resolved->id);
        $this->assertNotSame($filtered->id, $resolved->id);
    }

    public function test_mandatory_queue_excludes_reviewed_and_p4_but_keeps_deferred(): void
    {
        $run = $this->acceptedCurationRun();
        $review = $this->curatedAudit($run, Product::factory()->create(['name' => 'Needs Review']));
        $deferred = $this->curatedAudit($run, Product::factory()->create(['name' => 'Deferred Gift']));
        $advisory = $this->curatedAudit($run, Product::factory()->create(['name' => 'Advisory Gift']), [
            'requires_human_review' => false,
            'recommendation' => CurationRecommendation::Keep,
            'gift_score' => 82,
            'catalog_value_score' => 74,
        ]);
        $reviewed = $this->curatedAudit($run, Product::factory()->create(['name' => 'Already Reviewed']));

        app(RecordProductCurationDecisionAction::class)->execute(
            product: $reviewed->product,
            audit: $reviewed,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [ProductCurationDecisionReasonCode::StrongGift],
            reasonNotes: null,
            user: User::factory()->create(),
        );
        app(RecordProductCurationDecisionAction::class)->execute(
            product: $deferred->product,
            audit: $deferred,
            decision: ProductCurationDecision::Defer,
            reasonCodes: [ProductCurationDecisionReasonCode::NeedsMoreResearch],
            reasonNotes: 'Need a second look at the cluster.',
            user: User::factory()->create(),
        );

        $queue = app(QueryHumanCurationQueueAction::class);
        $mandatory = $queue->productIds(new HumanCurationQueueCriteria(view: 'needs_review'));
        $advisoryIds = $queue->productIds(new HumanCurationQueueCriteria(view: 'p4'));

        $this->assertEqualsCanonicalizing([$review->product_id, $deferred->product_id], $mandatory);
        $this->assertNotContains($reviewed->product_id, $mandatory);
        $this->assertContains($advisory->product_id, $advisoryIds);
        $this->assertNotContains($advisory->product_id, $mandatory);
    }

    public function test_priority_buckets_are_deterministic(): void
    {
        $run = $this->acceptedCurationRun();
        $priority = app(ResolveProductCurationPriorityAction::class);

        $p0 = $this->curatedAudit($run, Product::factory()->create(['name' => 'Broken Evidence']), [
            'evidence_snapshot' => ['product_id' => 999, 'name' => ''],
            'issues' => [[
                'code' => 'semantic_response_invalid',
                'severity' => 'blocking',
                'message' => 'Malformed semantic response.',
                'context' => [],
            ]],
        ]);
        $incomplete = $this->curatedAudit($run, Product::factory()->create(['name' => 'Draft Gift Idea']), [
            'gift_score' => 0,
            'catalog_value_score' => 25,
            'issues' => [
                [
                    'code' => 'missing_commerce_evidence',
                    'severity' => 'warning',
                    'message' => 'Commerce evidence is missing.',
                    'context' => [],
                ],
                [
                    'code' => 'missing_primary_category',
                    'severity' => 'warning',
                    'message' => 'Primary category is missing.',
                    'context' => [],
                ],
            ],
        ]);
        $p1 = $this->curatedAudit($run, Product::factory()->create(['name' => 'Weak Wallet']), [
            'concept_key' => 'wallet',
            'concept_label' => 'Wallet',
            'catalog_value_score' => 22,
            'differentiation_factor' => 6,
            'peer_counts' => ['concept' => 4],
            'peer_product_ids' => ['concept' => [100, 175, 244, 245]],
            'issues' => [[
                'code' => 'low_catalog_value',
                'severity' => 'warning',
                'message' => 'Catalog value is low.',
                'context' => [],
            ]],
            'catalog_context_snapshot' => [
                'differentiation_strength' => 'weak',
                'relative_coverage' => ['concept_peer_count' => 4],
            ],
        ]);
        $p2 = $this->curatedAudit($run, Product::factory()->create(['name' => 'Wrong Husband Gift']), [
            'catalog_value_score' => 62,
            'differentiation_factor' => 13,
            'peer_counts' => ['concept' => 0],
            'taxonomy_differences' => [[
                'dimension' => 'relationships',
                'action' => 'remove',
                'taxonomy' => ['name' => 'Husband'],
                'severity' => 'material',
                'forces_human_review' => true,
                'reason' => 'The assignment is materially misleading.',
            ]],
            'issues' => [[
                'code' => 'relationship_overclassification',
                'severity' => 'material',
                'message' => 'Relationship mismatch.',
                'context' => ['forces_human_review' => true],
            ]],
        ]);
        $p3 = $this->curatedAudit($run, Product::factory()->create(['name' => 'Judgment Call']), [
            'gift_score' => 60,
            'catalog_value_score' => 48,
            'differentiation_factor' => 13,
            'peer_counts' => ['concept' => 0],
            'issues' => [[
                'code' => 'weak_gift_fit',
                'severity' => 'warning',
                'message' => 'Gift fit is borderline.',
                'context' => [],
            ]],
        ]);
        $p4 = $this->curatedAudit($run, Product::factory()->create(['name' => 'Healthy Gift']), [
            'requires_human_review' => false,
            'recommendation' => CurationRecommendation::Keep,
            'gift_score' => 84,
            'catalog_value_score' => 72,
            'peer_counts' => ['concept' => 0],
            'taxonomy_differences' => [[
                'dimension' => 'interests',
                'severity' => 'advisory',
                'forces_human_review' => false,
                'taxonomy' => ['name' => 'Travel'],
                'reason' => 'Optional additional fit.',
            ]],
        ]);

        $this->assertSame(ProductCurationPriority::P0, $priority->execute($p0));
        $this->assertSame(ProductCurationPriority::P0, $priority->execute($incomplete));
        $this->assertSame(ProductCurationPriority::P1, $priority->execute($p1));
        $this->assertSame(ProductCurationPriority::P2, $priority->execute($p2));
        $this->assertSame(ProductCurationPriority::P3, $priority->execute($p3));
        $this->assertSame(ProductCurationPriority::P4, $priority->execute($p4));

        $queue = app(QueryHumanCurationQueueAction::class);
        $this->assertSame(
            [$incomplete->product_id, $p0->product_id],
            $queue->productIds(new HumanCurationQueueCriteria(view: 'p0')),
        );
        $this->assertSame([$p1->product_id], $queue->productIds(new HumanCurationQueueCriteria(view: 'p1')));
        $this->assertSame([$p2->product_id], $queue->productIds(new HumanCurationQueueCriteria(view: 'p2')));
        $this->assertSame([$p3->product_id], $queue->productIds(new HumanCurationQueueCriteria(view: 'p3')));
        $this->assertSame([$p4->product_id], $queue->productIds(new HumanCurationQueueCriteria(view: 'p4')));

        $ordered = $queue->productIds(new HumanCurationQueueCriteria(view: 'needs_review'));
        $this->assertSame([
            $incomplete->product_id,
            $p0->product_id,
            $p1->product_id,
            $p2->product_id,
            $p3->product_id,
        ], $ordered);
    }

    public function test_filters_and_sorting_use_accepted_audit_evidence(): void
    {
        $run = $this->acceptedCurationRun();
        $low = $this->curatedAudit($run, Product::factory()->create(['status' => ProductStatus::Draft, 'name' => 'Low Score']), [
            'gift_score' => 40,
            'catalog_value_score' => 20,
            'concept_key' => 'wallet',
            'concept_label' => 'Wallet',
            'peer_counts' => ['concept' => 1],
            'ai_confidence' => CurationAiConfidence::Low,
            'recommendation' => CurationRecommendation::Review,
            'evidence_snapshot' => [
                'product_id' => 0,
                'name' => 'Low Score',
                'status' => 'draft',
                'price' => ['amount' => '500', 'currency' => 'INR', 'compare_at_amount' => null],
                'taxonomy' => [
                    'relationships' => [['id' => 11, 'name' => 'Husband', 'slug' => 'husband']],
                    'occasions' => [],
                    'interests' => [],
                    'gift_types' => [],
                ],
                'offers' => [],
                'images' => [],
                'provenance' => [],
            ],
        ]);
        $low->update([
            'evidence_snapshot' => array_merge($low->evidence_snapshot, ['product_id' => $low->product_id]),
        ]);
        $high = $this->curatedAudit($run, Product::factory()->create(['status' => ProductStatus::Published, 'name' => 'High Score']), [
            'gift_score' => 88,
            'catalog_value_score' => 70,
            'concept_key' => 'tea-ritual',
            'concept_label' => 'Tea Ritual',
            'peer_counts' => ['concept' => 0],
            'ai_confidence' => CurationAiConfidence::High,
            'recommendation' => CurationRecommendation::Keep,
            'requires_human_review' => false,
        ]);

        $queue = app(QueryHumanCurationQueueAction::class);

        $this->assertSame(
            [$low->product_id],
            $queue->productIds(new HumanCurationQueueCriteria(
                view: 'all',
                giftScoreMax: 50,
                hasConceptPeers: true,
                aiConfidence: CurationAiConfidence::Low,
            )),
        );
        $this->assertSame(
            [$high->product_id],
            $queue->productIds(new HumanCurationQueueCriteria(
                view: 'all',
                catalogValueMin: 60,
                recommendation: CurationRecommendation::Keep,
                status: ProductStatus::Published,
            )),
        );
        $this->assertSame(
            [$low->product_id, $high->product_id],
            $queue->productIds(new HumanCurationQueueCriteria(view: 'all', sort: 'gift_score_asc')),
        );
        $this->assertSame(
            [$high->product_id, $low->product_id],
            $queue->productIds(new HumanCurationQueueCriteria(view: 'all', sort: 'catalog_value_desc')),
        );
    }

    public function test_concept_peers_and_progress_use_the_accepted_run(): void
    {
        $run = $this->acceptedCurationRun();
        $first = $this->curatedAudit($run, Product::factory()->create(['name' => 'Wallet A']), [
            'concept_key' => 'wallet',
            'concept_label' => 'Wallet',
            'gift_score' => 62,
            'catalog_value_score' => 12,
            'peer_counts' => ['concept' => 1],
        ]);
        $second = $this->curatedAudit($run, Product::factory()->create(['name' => 'Wallet B']), [
            'concept_key' => 'wallet',
            'concept_label' => 'Wallet',
            'gift_score' => 68,
            'catalog_value_score' => 28,
            'peer_counts' => ['concept' => 1],
        ]);

        $peers = app(ResolveProductConceptPeersAction::class)->execute($first->product, $first, $run);
        $this->assertSame('wallet', $peers['concept_key']);
        $this->assertSame($first->product_id, $peers['current']?->productId);
        $this->assertCount(1, $peers['peers']);
        $this->assertSame($second->product_id, $peers['peers'][0]->productId);
        $this->assertSame(28, $peers['peers'][0]->catalogValue);

        app(RecordProductCurationDecisionAction::class)->execute(
            product: $first->product,
            audit: $first,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [ProductCurationDecisionReasonCode::GoodValueForMoney],
            reasonNotes: null,
            user: User::factory()->create(),
        );

        $progress = app(ResolveCurationReviewProgressAction::class);
        $concept = $progress->forConcept('wallet', $run);
        $overall = $progress->execute($run);

        $this->assertSame(2, $concept?->products);
        $this->assertSame(1, $concept?->reviewed);
        $this->assertSame(1, $concept?->remaining);
        $this->assertSame(2, $overall->mandatoryReview);
        $this->assertSame(1, $overall->decided);
        $this->assertSame(1, $overall->remaining);
        $this->assertCount(1, $progress->clusteredConcepts($run));
    }

    public function test_p1_queue_groups_by_concept_cluster(): void
    {
        $run = $this->acceptedCurationRun();
        $speakerA = $this->curatedAudit($run, Product::factory()->create(['name' => 'Speaker A']), [
            'concept_key' => 'portable-bluetooth-speaker',
            'concept_label' => 'Portable Bluetooth Speaker',
            'catalog_value_score' => 18,
            'differentiation_factor' => 6,
            'peer_counts' => ['concept' => 1],
            'catalog_context_snapshot' => ['differentiation_strength' => 'weak'],
        ]);
        $speakerB = $this->curatedAudit($run, Product::factory()->create(['name' => 'Speaker B']), [
            'concept_key' => 'portable-bluetooth-speaker',
            'concept_label' => 'Portable Bluetooth Speaker',
            'catalog_value_score' => 30,
            'differentiation_factor' => 6,
            'peer_counts' => ['concept' => 1],
            'catalog_context_snapshot' => ['differentiation_strength' => 'weak'],
        ]);
        $walletLow = $this->curatedAudit($run, Product::factory()->create(['name' => 'Wallet Low']), [
            'concept_key' => 'wallet',
            'concept_label' => 'Wallet',
            'catalog_value_score' => 10,
            'differentiation_factor' => 6,
            'peer_counts' => ['concept' => 2],
            'catalog_context_snapshot' => ['differentiation_strength' => 'weak'],
        ]);
        $walletMid = $this->curatedAudit($run, Product::factory()->create(['name' => 'Wallet Mid']), [
            'concept_key' => 'wallet',
            'concept_label' => 'Wallet',
            'catalog_value_score' => 20,
            'differentiation_factor' => 6,
            'peer_counts' => ['concept' => 2],
            'catalog_context_snapshot' => ['differentiation_strength' => 'weak'],
        ]);
        $walletHigh = $this->curatedAudit($run, Product::factory()->create(['name' => 'Wallet High']), [
            'concept_key' => 'wallet',
            'concept_label' => 'Wallet',
            'catalog_value_score' => 24,
            'differentiation_factor' => 6,
            'peer_counts' => ['concept' => 2],
            'catalog_context_snapshot' => ['differentiation_strength' => 'weak'],
        ]);

        $queue = app(QueryHumanCurationQueueAction::class);
        $ids = $queue->productIds(new HumanCurationQueueCriteria(view: 'p1'));

        $this->assertSame([
            $walletLow->product_id,
            $walletMid->product_id,
            $walletHigh->product_id,
            $speakerA->product_id,
            $speakerB->product_id,
        ], $ids);

        $this->assertSame(
            $walletMid->product_id,
            $queue->clusterNeighbor($walletLow->product_id, 'next', 'wallet', new HumanCurationQueueCriteria(view: 'p1')),
        );
        $this->assertSame(
            $speakerA->product_id,
            $queue->clusterNeighbor($walletHigh->product_id, 'next', 'wallet', new HumanCurationQueueCriteria(view: 'p1')),
        );
    }

    public function test_p2_queue_requires_completed_remediation_and_keep_clears_false_positives(): void
    {
        $run = $this->acceptedCurationRun();
        $user = User::factory()->create();
        $p2Keep = $this->curatedAudit($run, Product::factory()->create(['name' => 'False Positive Taxonomy']), [
            'taxonomy_differences' => [[
                'dimension' => 'relationships',
                'action' => 'remove',
                'taxonomy' => ['name' => 'Husband'],
                'severity' => 'material',
                'forces_human_review' => true,
                'reason' => 'The assignment looked misleading in the audit.',
            ]],
        ]);
        $p2Reclassify = $this->curatedAudit($run, Product::factory()->create(['name' => 'Needs Reclass']), [
            'taxonomy_differences' => [[
                'dimension' => 'occasions',
                'action' => 'remove',
                'taxonomy' => ['name' => 'Graduation'],
                'severity' => 'material',
                'forces_human_review' => true,
                'reason' => 'Graduation is not a defensible occasion.',
            ]],
        ]);
        $p2Defer = $this->curatedAudit($run, Product::factory()->create(['name' => 'Deferred Taxonomy']), [
            'taxonomy_differences' => [[
                'dimension' => 'interests',
                'action' => 'remove',
                'taxonomy' => ['name' => 'Gaming'],
                'severity' => 'material',
                'forces_human_review' => true,
                'reason' => 'Need a better interest label that does not exist yet.',
            ]],
        ]);
        $advisory = $this->curatedAudit($run, Product::factory()->create(['name' => 'Advisory Fit Only']), [
            'requires_human_review' => false,
            'recommendation' => CurationRecommendation::Keep,
            'gift_score' => 84,
            'catalog_value_score' => 72,
            'taxonomy_differences' => [[
                'dimension' => 'interests',
                'severity' => 'advisory',
                'forces_human_review' => false,
                'taxonomy' => ['name' => 'Travel'],
                'reason' => 'Optional additional fit.',
            ]],
        ]);
        $archivedP1 = $this->curatedAudit($run, Product::factory()->create([
            'name' => 'Archived Redundant Wallet',
            'status' => ProductStatus::Archived,
        ]), [
            'concept_key' => 'wallet',
            'concept_label' => 'Wallet',
            'catalog_value_score' => 12,
            'differentiation_factor' => 4,
            'peer_counts' => ['concept' => 2],
            'catalog_context_snapshot' => ['differentiation_strength' => 'weak'],
        ]);

        $keepAttached = $this->attachMerchandisingTaxonomy($p2Keep->product);
        $reclassAttached = $this->attachMerchandisingTaxonomy($p2Reclassify->product);

        app(RecordProductCurationDecisionAction::class)->execute(
            product: $archivedP1->product,
            audit: $archivedP1,
            decision: ProductCurationDecision::Deactivate,
            reasonCodes: [ProductCurationDecisionReasonCode::RedundantConcept],
            reasonNotes: 'Already archived in P1.',
            user: $user,
        );
        app(RecordProductCurationDecisionAction::class)->execute(
            product: $p2Keep->product,
            audit: $p2Keep,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [
                ProductCurationDecisionReasonCode::StrongPracticalValue,
                ProductCurationDecisionReasonCode::AuditAnomaly,
            ],
            reasonNotes: 'Current Husband assignment is still merchandising-valid.',
            user: $user,
        );
        $reclassify = app(RecordProductCurationDecisionAction::class)->execute(
            product: $p2Reclassify->product,
            audit: $p2Reclassify,
            decision: ProductCurationDecision::Reclassify,
            reasonCodes: [ProductCurationDecisionReasonCode::TaxonomyOccasionIssue],
            reasonNotes: 'Graduation should be removed.',
            user: $user,
            taxonomyProposal: $this->taxonomyProposal(remove: [$reclassAttached['relationship']->id]),
        );
        app(RecordProductCurationDecisionAction::class)->execute(
            product: $p2Defer->product,
            audit: $p2Defer,
            decision: ProductCurationDecision::Defer,
            reasonCodes: [ProductCurationDecisionReasonCode::NeedsMoreResearch],
            reasonNotes: 'Need a taxonomy value that does not exist yet.',
            user: $user,
        );

        $queue = app(QueryHumanCurationQueueAction::class);

        $this->assertEqualsCanonicalizing(
            [$p2Reclassify->product_id, $p2Defer->product_id],
            $queue->productIds(new HumanCurationQueueCriteria(view: 'p2')),
        );
        $this->assertContains($p2Defer->product_id, $queue->productIds(new HumanCurationQueueCriteria(view: 'needs_review')));
        $this->assertNotContains($p2Keep->product_id, $queue->productIds(new HumanCurationQueueCriteria(view: 'p2')));
        $this->assertNotContains($advisory->product_id, $queue->productIds(new HumanCurationQueueCriteria(view: 'p2')));
        $this->assertContains($advisory->product_id, $queue->productIds(new HumanCurationQueueCriteria(view: 'p4')));
        $this->assertNotContains($archivedP1->product_id, $queue->productIds(new HumanCurationQueueCriteria(view: 'p2')));
        $this->assertNotContains($archivedP1->product_id, $queue->productIds(new HumanCurationQueueCriteria(view: 'p1')));

        app(ApplyHumanCurationReclassificationAction::class)->execute($reclassify, $user);
        $queue->flush();

        $this->assertSame([$p2Defer->product_id], $queue->productIds(new HumanCurationQueueCriteria(view: 'p2')));
        $this->assertContains($p2Defer->product_id, $queue->productIds(new HumanCurationQueueCriteria(view: 'needs_review')));
        $this->assertSame($keepAttached['relationship']->id, $p2Keep->product->fresh()->relationships()->first()?->id);
    }

    public function test_queue_index_avoids_n_plus_one_queries(): void
    {
        $run = $this->acceptedCurationRun();

        foreach (range(1, 8) as $index) {
            $this->curatedAudit($run, Product::factory()->create(['name' => 'Queue Gift '.$index]));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $ids = app(QueryHumanCurationQueueAction::class)->productIds(
            new HumanCurationQueueCriteria(view: 'needs_review'),
        );

        $this->assertCount(8, $ids);
        $this->assertLessThan(10, count(DB::getQueryLog()));
    }
}
