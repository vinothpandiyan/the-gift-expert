<?php

namespace Tests\Feature\LaunchPublication;

use App\Actions\LaunchPublication\BuildLaunchPublicationReadinessReportAction;
use App\Actions\LaunchPublication\CaptureLaunchCatalogFingerprintAction;
use App\Actions\LaunchPublication\ExecuteLaunchPublicationAction;
use App\Actions\LaunchPublication\PublishLaunchCatalogCohortAction;
use App\Actions\LaunchPublication\SelectLaunchPublicationCohortAction;
use App\Actions\Product\PublishProductAction;
use App\Enums\LaunchPublicationResult;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\BuildsLaunchPublicationFixtures;
use Tests\TestCase;

class PublishLaunchCatalogCohortTest extends TestCase
{
    use BuildsLaunchPublicationFixtures;
    use RefreshDatabase;

    public function test_it_publishes_ready_keep_family_products_through_the_canonical_action(): void
    {
        $run = $this->acceptedCurationRun();
        $keep = $this->retainedDraft(ProductCurationDecision::Keep, run: $run);
        $this->attachRelationship($keep['product']);

        $publish = Mockery::mock(PublishProductAction::class);
        $publish->shouldReceive('execute')->once()->andReturn(['warnings' => []]);
        $this->app->instance(PublishProductAction::class, $publish);

        $attempts = app(PublishLaunchCatalogCohortAction::class)->execute(
            [$keep['product']->id],
            'test:launch',
        );

        $this->assertSame(LaunchPublicationResult::Published, $attempts[0]->result);
        $this->assertSame(ProductCurationDecision::Keep->value, $attempts[0]->humanDecision);
    }

    public function test_it_skips_defer_and_archived_products_with_reasons(): void
    {
        $run = $this->acceptedCurationRun();
        $defer = $this->retainedDraft(ProductCurationDecision::Defer, run: $run)['product'];
        $archived = $this->retainedDraft(ProductCurationDecision::Keep, run: $run)['product'];
        $archived->update(['status' => ProductStatus::Archived]);

        $attempts = app(PublishLaunchCatalogCohortAction::class)->execute(
            [$defer->id, $archived->id],
            'test:launch',
        );

        $this->assertSame(LaunchPublicationResult::Skipped, $attempts[0]->result);
        $this->assertSame('not_editorially_approved', $attempts[0]->reason);
        $this->assertSame(LaunchPublicationResult::Skipped, $attempts[1]->result);
        $this->assertSame('archived', $attempts[1]->reason);
        $this->assertSame(ProductStatus::Draft, $defer->fresh()->status);
        $this->assertSame(ProductStatus::Archived, $archived->fresh()->status);
    }

    public function test_publication_does_not_mutate_human_decisions_taxonomy_or_audits(): void
    {
        $run = $this->acceptedCurationRun();
        $keep = $this->retainedDraft(ProductCurationDecision::Keep, run: $run);
        $relationship = $this->attachRelationship($keep['product']);
        $product = $keep['product'];

        $decisionCount = ProductCurationDecisionRecord::query()->count();
        $auditCount = ProductCurationAudit::query()->count();
        $runCount = $run->fresh()->audits()->count();
        $relationshipIds = $product->relationships()->pluck('relationships.id')->all();
        $classification = $product->taxonomy_classification_status;
        $humanOverridden = $product->taxonomy_classification_status;

        $result = app(ExecuteLaunchPublicationAction::class)->execute(
            [$product->id],
            app(BuildLaunchPublicationReadinessReportAction::class)->execute(),
            'test:launch',
        );

        $this->assertSame(1, $result['tally']['published']);
        $this->assertSame([], $result['reconciliation']['unexplained']);
        $this->assertSame($decisionCount, ProductCurationDecisionRecord::query()->count());
        $this->assertSame($auditCount, ProductCurationAudit::query()->count());
        $this->assertSame($runCount, $run->fresh()->audits()->count());
        $this->assertEqualsCanonicalizing(
            $relationshipIds,
            $product->fresh()->relationships()->pluck('relationships.id')->all(),
        );
        $this->assertSame($classification, $product->fresh()->taxonomy_classification_status);
        $this->assertSame($humanOverridden, $product->fresh()->taxonomy_classification_status);
        $this->assertSame(ProductCurationDecision::Keep, $product->fresh()->currentCurationDecision?->decision);
        $this->assertSame(ProductStatus::Published, $product->fresh()->status);
        $this->assertNotNull($product->fresh()->published_at);
        $this->assertSame($product->id, $result['attempts'][0]['product_id']);
        $this->assertSame('published', $result['attempts'][0]['result']);
        $this->assertSame([$relationship->id], $relationshipIds);
        $this->assertSame(
            $keep['audit']->gift_score,
            ProductCurationAudit::query()->whereKey($keep['audit']->id)->value('gift_score'),
        );
    }

    public function test_blocked_taxonomy_products_are_not_repaired(): void
    {
        $run = $this->acceptedCurationRun();
        $blocked = $this->retainedDraft(
            ProductCurationDecision::Keep,
            ['taxonomy_classification_status' => TaxonomyClassificationStatus::Review],
            $run,
        )['product'];
        $this->attachRelationship($blocked);
        $beforeRelationships = $blocked->relationships()->pluck('relationships.id')->all();
        $beforeCategories = DB::table('category_product')->where('product_id', $blocked->id)->get()->all();

        $attempts = app(PublishLaunchCatalogCohortAction::class)->execute([$blocked->id], 'test:launch');

        $this->assertSame(LaunchPublicationResult::Failed, $attempts[0]->result);
        $this->assertSame(ProductStatus::Draft, $blocked->fresh()->status);
        $this->assertSame(TaxonomyClassificationStatus::Review, $blocked->fresh()->taxonomy_classification_status);
        $this->assertEqualsCanonicalizing(
            $beforeRelationships,
            $blocked->fresh()->relationships()->pluck('relationships.id')->all(),
        );
        $this->assertEquals(
            $beforeCategories,
            DB::table('category_product')->where('product_id', $blocked->id)->get()->all(),
        );
    }

    public function test_cohort_selection_includes_ready_products_and_warns_on_concept_clusters(): void
    {
        $run = $this->acceptedCurationRun();
        $first = $this->retainedDraft(ProductCurationDecision::Keep, run: $run);
        $second = $this->retainedDraft(ProductCurationDecision::Keep, run: $run);
        $third = $this->retainedDraft(ProductCurationDecision::Keep, run: $run);
        $first['audit']->update(['concept_key' => 'shared-wallet', 'concept_label' => 'Wallet']);
        $second['audit']->update(['concept_key' => 'shared-wallet', 'concept_label' => 'Wallet']);
        $third['audit']->update(['concept_key' => 'shared-wallet', 'concept_label' => 'Wallet']);

        $readiness = app(BuildLaunchPublicationReadinessReportAction::class)->execute();
        $cohort = app(SelectLaunchPublicationCohortAction::class)->execute($readiness);

        $this->assertCount(3, $cohort['product_ids']);
        $this->assertCount(1, $cohort['concept_warnings']);
        $this->assertSame(3, $cohort['concept_warnings'][0]['count']);
    }

    public function test_fingerprint_capture_records_catalog_safety_counts(): void
    {
        $this->retainedDraft(ProductCurationDecision::Keep);

        $fingerprint = app(CaptureLaunchCatalogFingerprintAction::class)->execute();

        $this->assertSame(1, $fingerprint->counts['draft']);
        $this->assertSame(0, $fingerprint->counts['published']);
        $this->assertSame(1, $fingerprint->counts['human_decisions']);
        $this->assertSame(1, $fingerprint->counts['audit_runs']);
        $this->assertSame(1, $fingerprint->counts['audit_rows']);
        $this->assertNotEmpty($fingerprint->hash);
    }
}
