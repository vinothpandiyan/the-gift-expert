<?php

namespace Tests\Feature\CatalogCuration;

use App\Actions\CatalogCuration\EvaluateProductCurationSemanticsAction;
use App\CatalogCuration\ProductCurationEvidence;
use App\Enums\ProductCurationAuditOutcome;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ProductCurationAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_only_selects_and_writes_nothing(): void
    {
        $product = Product::factory()->create();
        $this->mock(EvaluateProductCurationSemanticsAction::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('execute');
        });

        $this->artisan('catalog:curation-audit', [
            '--product' => [(string) $product->id],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run completed')
            ->assertSuccessful();

        $this->assertSame(0, ProductCurationAuditRun::query()->count());
        $this->assertSame(0, ProductCurationAudit::query()->count());
    }

    public function test_batch_isolates_failures_and_failed_product_can_be_rerun_safely(): void
    {
        [$failedProduct, $completedProduct] = Product::factory()->count(2)->create();
        $originalStatuses = Product::query()->get()->mapWithKeys(
            fn (Product $product): array => [$product->id => $product->status->value],
        )->all();

        $this->mock(EvaluateProductCurationSemanticsAction::class, function (MockInterface $mock) use ($failedProduct): void {
            $mock->shouldReceive('execute')
                ->twice()
                ->andReturnUsing(function (ProductCurationEvidence $evidence) use ($failedProduct): array {
                    if ($evidence->productId === $failedProduct->id) {
                        throw new RuntimeException('Synthetic semantic failure.');
                    }

                    return $this->semanticResult();
                });
        });

        $this->artisan('catalog:curation-audit', [
            '--product' => [(string) $failedProduct->id, (string) $completedProduct->id],
        ])->assertFailed();

        $firstRun = ProductCurationAuditRun::query()->firstOrFail();
        $this->assertSame(1, $firstRun->products_completed);
        $this->assertSame(1, $firstRun->products_failed);
        $this->assertDatabaseHas('product_curation_audits', [
            'run_id' => $firstRun->id,
            'product_id' => $failedProduct->id,
            'outcome' => ProductCurationAuditOutcome::Failed->value,
        ]);
        $this->assertDatabaseHas('product_curation_audits', [
            'run_id' => $firstRun->id,
            'product_id' => $completedProduct->id,
            'outcome' => ProductCurationAuditOutcome::Completed->value,
        ]);

        $this->mock(EvaluateProductCurationSemanticsAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->once()->andReturn($this->semanticResult());
        });

        $this->artisan('catalog:curation-audit', [
            '--product' => [(string) $failedProduct->id],
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(2, ProductCurationAuditRun::query()->count());
        $latest = $failedProduct->curationAudits()->latest('id')->firstOrFail();
        $this->assertSame(ProductCurationAuditOutcome::Completed, $latest->outcome);
        $this->assertTrue($latest->requires_human_review);
        $this->assertNull($latest->gift_score_components['value_for_money']['score']);
        $this->assertSame('curated-gift', $latest->concept_key);
        $this->assertSame('Curated Gift', $latest->concept_label);
        $this->assertSame(['Specific utility'], $latest->strengths);
        $this->assertSame([], $latest->suggested_relationships);
        $this->assertNotNull($latest->context_fingerprint);
        $this->assertNotNull($latest->context_calculated_at);
        $this->assertSame(
            $originalStatuses,
            Product::query()->get()->mapWithKeys(
                fn (Product $product): array => [$product->id => $product->status->value],
            )->all(),
        );
        $this->assertSame(0, $failedProduct->relationships()->count());
        $this->assertSame(0, $failedProduct->occasions()->count());
    }

    public function test_force_recalculation_reuses_fresh_semantics_without_ai(): void
    {
        $product = Product::factory()->create(['status' => ProductStatus::Published]);
        $this->mock(EvaluateProductCurationSemanticsAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->once()->andReturn($this->semanticResult());
        });

        $this->artisan('catalog:curation-audit', ['--product' => [(string) $product->id]])->assertSuccessful();

        $this->artisan('catalog:curation-audit', [
            '--product' => [(string) $product->id],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Selected 0 product(s).')
            ->assertSuccessful();

        $this->artisan('catalog:curation-audit', [
            '--product' => [(string) $product->id],
            '--only-missing' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Selected 0 product(s).')
            ->assertSuccessful();

        $this->mock(EvaluateProductCurationSemanticsAction::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('execute');
        });

        $this->artisan('catalog:curation-audit', [
            '--product' => [(string) $product->id],
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(2, $product->curationAudits()->count());
        $this->assertSame(0, $product->curationAudits()->latest('id')->value('semantic_duration_ms'));
    }

    public function test_new_evaluator_and_prompt_versions_append_history_without_mutating_prior_audit(): void
    {
        $product = Product::factory()->create();
        $this->mock(EvaluateProductCurationSemanticsAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->once()->andReturn($this->semanticResult());
        });
        $this->artisan('catalog:curation-audit', ['--product' => [(string) $product->id]])->assertSuccessful();
        $first = $product->curationAudits()->sole();
        $firstSnapshot = $first->only(['id', 'run_id', 'semantic_evaluator_version', 'prompt_version', 'semantic_evaluation']);

        config()->set('catalog_curation.versions.semantic_evaluator', 'calibration-test');
        config()->set('catalog_curation.versions.prompt', 'calibration-test');
        $this->mock(EvaluateProductCurationSemanticsAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->once()->andReturn($this->semanticResult());
        });
        $this->artisan('catalog:curation-audit', [
            '--product' => [(string) $product->id],
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(2, $product->curationAudits()->count());
        $this->assertSame($firstSnapshot, $first->fresh()->only(array_keys($firstSnapshot)));
        $this->assertSame('calibration-test', $product->curationAudits()->latest('id')->value('prompt_version'));
    }

    public function test_normal_selection_detects_changed_semantic_evidence(): void
    {
        $product = Product::factory()->create();
        $this->mock(EvaluateProductCurationSemanticsAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->once()->andReturn($this->semanticResult());
        });
        $this->artisan('catalog:curation-audit', ['--product' => [(string) $product->id]])->assertSuccessful();

        $product->update(['description' => 'Materially changed product evidence for a different use case.']);

        $this->artisan('catalog:curation-audit', [
            '--product' => [(string) $product->id],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Selected 1 product(s).')
            ->assertSuccessful();
    }

    public function test_normal_selection_detects_stale_context_when_catalog_peers_evolve(): void
    {
        [$target, $newPeer] = Product::factory()->count(2)->create();
        $this->mock(EvaluateProductCurationSemanticsAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->once()->andReturn($this->semanticResult());
        });
        $this->artisan('catalog:curation-audit', ['--product' => [(string) $target->id]])->assertSuccessful();

        $this->mock(EvaluateProductCurationSemanticsAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->once()->andReturn($this->semanticResult());
        });
        $this->artisan('catalog:curation-audit', ['--product' => [(string) $newPeer->id]])->assertSuccessful();

        $this->artisan('catalog:curation-audit', [
            '--product' => [(string) $target->id],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Selected 1 product(s).')
            ->assertSuccessful();
    }

    /**
     * @return array{evaluation: array<string, mixed>, issues: list<array<string, mixed>>}
     */
    private function semanticResult(): array
    {
        return [
            'evaluation' => [
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
                'concept_key_candidate' => 'Curated Gift',
                'concept_label_candidate' => 'Curated Gift',
                'concept_key' => 'curated-gift',
                'concept_label' => 'Curated Gift',
                'why_this_gift' => 'Useful for someone who values a practical item in their daily routine.',
                'confidence' => 'high',
                'strengths' => ['Specific utility'],
                'differentiation_signals' => ['Specific utility'],
                'differentiation_strength' => 'medium',
                'niche_signals' => [],
                'niche_contribution' => 'none',
            ],
            'issues' => [],
        ];
    }
}
