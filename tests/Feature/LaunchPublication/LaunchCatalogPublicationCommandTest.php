<?php

namespace Tests\Feature\LaunchPublication;

use App\Enums\ProductCurationDecision;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\BuildsLaunchPublicationFixtures;
use Tests\TestCase;

class LaunchCatalogPublicationCommandTest extends TestCase
{
    use BuildsLaunchPublicationFixtures;
    use RefreshDatabase;

    public function test_preview_does_not_mutate_catalog_or_curation_history(): void
    {
        $keep = $this->retainedDraft(ProductCurationDecision::Keep);
        $decisionCount = ProductCurationDecisionRecord::query()->count();
        $auditCount = ProductCurationAudit::query()->count();

        $exitCode = Artisan::call('catalog:launch-publication');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Launch publication preview', $output);
        $this->assertStringContainsString('Retained draft: 1', $output);
        $this->assertStringContainsString('Publish-ready: 1', $output);

        $this->assertSame(ProductStatus::Draft, $keep['product']->fresh()->status);
        $this->assertSame($decisionCount, ProductCurationDecisionRecord::query()->count());
        $this->assertSame($auditCount, ProductCurationAudit::query()->count());
    }

    public function test_execute_publishes_the_cohort_and_prints_a_manifest(): void
    {
        $keep = $this->retainedDraft(ProductCurationDecision::Keep);
        $feature = $this->retainedDraft(ProductCurationDecision::Feature);

        $this->artisan('catalog:launch-publication', ['--execute' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Newly published: 2')
            ->expectsOutputToContain('Fingerprint reconciliation');

        $this->assertSame(ProductStatus::Published, $keep['product']->fresh()->status);
        $this->assertSame(ProductStatus::Published, $feature['product']->fresh()->status);
        $this->assertSame(2, Product::query()->published()->count());
        $this->assertSame(2, ProductCurationDecisionRecord::query()->count());
        $this->assertSame(2, ProductCurationAudit::query()->count());
    }

    public function test_json_preview_includes_readiness_and_cohort_fields(): void
    {
        $this->retainedDraft(ProductCurationDecision::KeepNiche);

        $exitCode = Artisan::call('catalog:launch-publication', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"retained_draft": 1', $output);
        $this->assertStringContainsString('"publish_ready": 1', $output);
        $this->assertStringContainsString('"human_decision": "keep_niche"', $output);
        $this->assertStringNotContainsString('_internal', $output);
    }
}
