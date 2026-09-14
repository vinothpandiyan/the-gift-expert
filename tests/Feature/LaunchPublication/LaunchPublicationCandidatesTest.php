<?php

namespace Tests\Feature\LaunchPublication;

use App\Actions\LaunchPublication\BuildLaunchPublicationReadinessReportAction;
use App\Actions\LaunchPublication\QueryLaunchPublicationCandidatesAction;
use App\Actions\Product\AssessProductPublicationRequirementsAction;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsLaunchPublicationFixtures;
use Tests\TestCase;

class LaunchPublicationCandidatesTest extends TestCase
{
    use BuildsLaunchPublicationFixtures;
    use RefreshDatabase;

    public function test_it_includes_feature_keep_and_keep_niche_live_drafts(): void
    {
        $run = $this->acceptedCurationRun();
        $feature = $this->retainedDraft(ProductCurationDecision::Feature, run: $run)['product'];
        $keep = $this->retainedDraft(ProductCurationDecision::Keep, run: $run)['product'];
        $niche = $this->retainedDraft(ProductCurationDecision::KeepNiche, run: $run)['product'];

        $ids = app(QueryLaunchPublicationCandidatesAction::class)->execute()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$feature->id, $keep->id, $niche->id], $ids);
    }

    public function test_it_excludes_archived_products_even_when_kept(): void
    {
        $run = $this->acceptedCurationRun();
        $archived = $this->retainedDraft(ProductCurationDecision::Keep, run: $run)['product'];
        $archived->update(['status' => ProductStatus::Archived]);
        $this->retainedDraft(ProductCurationDecision::Keep, run: $run);

        $ids = app(QueryLaunchPublicationCandidatesAction::class)->execute()->pluck('id')->all();

        $this->assertNotContains($archived->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_it_excludes_defer_remove_candidate_and_products_without_keep_family_decisions(): void
    {
        $run = $this->acceptedCurationRun();
        $defer = $this->retainedDraft(ProductCurationDecision::Defer, run: $run)['product'];
        $remove = $this->retainedDraft(ProductCurationDecision::RemoveCandidate, run: $run)['product'];
        $undecided = $this->publishableDraft();
        $this->curatedAudit($run, $undecided);
        $keep = $this->retainedDraft(ProductCurationDecision::Keep, run: $run)['product'];

        $ids = app(QueryLaunchPublicationCandidatesAction::class)->execute()->pluck('id')->all();

        $this->assertSame([$keep->id], $ids);
        $this->assertNotContains($defer->id, $ids);
        $this->assertNotContains($remove->id, $ids);
        $this->assertNotContains($undecided->id, $ids);
    }

    public function test_readiness_reuses_the_canonical_publication_gate(): void
    {
        $run = $this->acceptedCurationRun();
        $ready = $this->retainedDraft(ProductCurationDecision::Keep, run: $run)['product'];
        $blocked = $this->retainedDraft(
            ProductCurationDecision::Keep,
            ['taxonomy_classification_status' => TaxonomyClassificationStatus::Review],
            $run,
        )['product'];

        $report = app(BuildLaunchPublicationReadinessReportAction::class)->execute();
        $assess = app(AssessProductPublicationRequirementsAction::class);

        $this->assertSame(2, $report['retained_draft']);
        $this->assertSame(1, $report['publish_ready']);
        $this->assertSame(1, $report['blocked']);

        $readyRow = collect($report['rows'])->firstWhere('productId', $ready->id);
        $blockedRow = collect($report['rows'])->firstWhere('productId', $blocked->id);

        $this->assertTrue($readyRow->ready);
        $this->assertSame([], $assess->execute($ready->fresh())['error_codes']);
        $this->assertFalse($blockedRow->ready);
        $this->assertSame(
            $assess->execute($blocked->fresh())['error_codes'],
            $blockedRow->blockingCodes,
        );
        $this->assertSame(['classification_not_publishable' => 1], $report['blocker_counts']);
        $this->assertSame(['taxonomy' => 1], $report['blocker_groups']);
    }

    public function test_already_published_keep_products_are_not_new_candidates(): void
    {
        $run = $this->acceptedCurationRun();
        $published = $this->retainedDraft(ProductCurationDecision::Keep, run: $run)['product'];
        $published->update([
            'status' => ProductStatus::Published,
            'published_at' => now(),
        ]);

        $ids = app(QueryLaunchPublicationCandidatesAction::class)->execute()->pluck('id')->all();

        $this->assertSame([], $ids);
        $this->assertSame(ProductStatus::Published, Product::query()->find($published->id)?->status);
    }
}
