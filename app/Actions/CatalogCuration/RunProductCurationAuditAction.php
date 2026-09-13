<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\ProductCurationEvidence;
use App\Enums\ProductCurationAuditOutcome;
use App\Enums\ProductCurationRunStatus;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunProductCurationAuditAction
{
    public function __construct(
        private BuildProductCurationEvidenceAction $buildEvidence,
        private EvaluateProductCurationSemanticsAction $evaluateSemantics,
        private CalculateGiftScoreAction $calculateGiftScore,
        private CalculateCatalogCurationContextAction $calculateContext,
        private BuildCurationTaxonomyDifferencesAction $buildDifferences,
        private BuildCurationIssuesAction $buildIssues,
        private RecommendProductCurationAction $recommend,
        private AmendCurationReviewAction $amendReview,
        private ResolveCatalogRoleAction $resolveRole,
    ) {}

    /**
     * @param  list<int>  $productIds
     * @param  array<string, mixed>  $options
     * @param  null|callable(string, ProductCurationAudit): void  $progress
     */
    public function execute(
        array $productIds,
        array $options,
        ?ProductCurationAuditRun $run = null,
        ?callable $progress = null,
    ): ProductCurationAuditRun {
        $run ??= ProductCurationAuditRun::query()->create([
            'status' => ProductCurationRunStatus::Pending,
            'options' => $options,
            'summary' => [
                'versions' => $this->versions(),
                'eligible_count' => count($productIds),
            ],
            'products_total' => count($productIds),
        ]);

        $run->update([
            'status' => ProductCurationRunStatus::Running,
            'started_at' => $run->started_at ?? now(),
            'failure' => null,
            'summary' => [
                ...($run->summary ?? []),
                'versions' => $this->versions(),
                'eligible_count' => (int) $run->products_total,
            ],
        ]);

        foreach ($productIds as $productId) {
            $product = Product::query()->find($productId);

            if (! $product instanceof Product) {
                continue;
            }

            $evidence = $this->buildEvidence->execute($product);
            $evidenceFingerprint = $evidence->fingerprint();
            ProductCurationAudit::query()->firstOrCreate(
                ['run_id' => $run->id, 'product_id' => $product->id],
                [
                    'outcome' => ProductCurationAuditOutcome::Pending,
                    'evidence_snapshot' => $evidence->toArray(),
                    'evidence_fingerprint' => $evidenceFingerprint,
                    'semantic_fingerprint' => $this->semanticFingerprint($evidenceFingerprint),
                    ...$this->versions(),
                ],
            );
        }

        $this->semanticPass($run, $progress);
        $this->contextPass($run, $progress);

        $counts = $run->audits()
            ->selectRaw('outcome, count(*) as aggregate')
            ->groupBy('outcome')
            ->pluck('aggregate', 'outcome')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $failed = $counts[ProductCurationAuditOutcome::Failed->value] ?? 0;
        $completed = $counts[ProductCurationAuditOutcome::Completed->value] ?? 0;
        $semanticReused = $run->audits()
            ->whereNotNull('semantic_evaluation')
            ->where('semantic_duration_ms', 0)
            ->count();
        $freshSemantic = $run->audits()
            ->whereNotNull('semantic_evaluation')
            ->where('semantic_duration_ms', '>', 0)
            ->count();
        $summary = [
            'versions' => $this->versions(),
            'eligible_count' => $run->audits()->count(),
            'outcomes' => $counts,
            'semantic_reused' => $semanticReused,
            'fresh_semantic' => $freshSemantic,
            'deterministic_only_rescored' => $semanticReused,
            'gift_score_average' => $run->audits()->where('outcome', ProductCurationAuditOutcome::Completed)->avg('gift_score'),
            'catalog_value_average' => $run->audits()->where('outcome', ProductCurationAuditOutcome::Completed)->avg('catalog_value_score'),
        ];

        $run->update([
            'status' => $failed > 0 ? ProductCurationRunStatus::CompletedWithErrors : ProductCurationRunStatus::Completed,
            'products_total' => $run->audits()->count(),
            'products_processed' => $completed + $failed,
            'products_completed' => $completed,
            'products_failed' => $failed,
            'summary' => $summary,
            'finished_at' => now(),
        ]);

        Log::info('catalog_curation.run_completed', [
            'run_id' => $run->id,
            'status' => $run->status->value,
            'summary' => $summary,
        ]);

        return $run->refresh();
    }

    /**
     * @param  null|callable(string, ProductCurationAudit): void  $progress
     */
    private function semanticPass(ProductCurationAuditRun $run, ?callable $progress): void
    {
        $run->audits()
            ->where('outcome', ProductCurationAuditOutcome::Pending)
            ->orderBy('id')
            ->each(function (ProductCurationAudit $audit) use ($progress): void {
                $started = hrtime(true);

                try {
                    $evidence = ProductCurationEvidence::fromArray($audit->evidence_snapshot);
                    $reusable = $this->reusableSemanticAudit($audit);

                    if ($reusable instanceof ProductCurationAudit) {
                        $semantic = $reusable->semantic_evaluation;
                        $issues = $semantic['resolution_issues'] ?? [];
                        $model = $reusable->ai_model;
                        $duration = 0;
                    } else {
                        $result = $this->evaluateSemantics->execute($evidence);
                        $semantic = $result['evaluation'];
                        $issues = $result['issues'];
                        $semantic['resolution_issues'] = $issues;
                        $model = config('commercial_sourcing.enrichment.model');
                        $duration = $this->elapsedMilliseconds($started);
                    }

                    $audit->update([
                        'outcome' => ProductCurationAuditOutcome::SemanticReady,
                        'semantic_evaluation' => $semantic,
                        'gift_intents' => $semantic['gift_intents'],
                        'concept_key' => $semantic['concept_key'],
                        'concept_label' => $semantic['concept_label'],
                        'why_this_gift' => $semantic['why_this_gift'],
                        'strengths' => $semantic['strengths'],
                        'suggested_relationships' => $semantic['taxonomy_suggestions']['relationships'],
                        'suggested_occasions' => $semantic['taxonomy_suggestions']['occasions'],
                        'suggested_interests' => $semantic['taxonomy_suggestions']['interests'],
                        'suggested_gift_types' => $semantic['taxonomy_suggestions']['gift_types'],
                        'ai_confidence' => $semantic['confidence'],
                        'issues' => $issues,
                        'ai_model' => $model,
                        'semantic_duration_ms' => $duration,
                        'semantic_completed_at' => now(),
                        'failure' => null,
                    ]);

                    Log::info('catalog_curation.semantic_ready', [
                        'run_id' => $audit->run_id,
                        'audit_id' => $audit->id,
                        'product_id' => $audit->product_id,
                        'reused' => $reusable instanceof ProductCurationAudit,
                        'duration_ms' => $duration,
                    ]);
                    if ($progress !== null) {
                        $progress('semantic_ready', $audit->refresh());
                    }
                } catch (Throwable $exception) {
                    $audit->update([
                        'outcome' => ProductCurationAuditOutcome::Failed,
                        'semantic_duration_ms' => $this->elapsedMilliseconds($started),
                        'failure' => $exception->getMessage(),
                    ]);
                    Log::warning('catalog_curation.semantic_failed', [
                        'run_id' => $audit->run_id,
                        'audit_id' => $audit->id,
                        'product_id' => $audit->product_id,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                    if ($progress !== null) {
                        $progress('failed', $audit->refresh());
                    }
                }
            });
    }

    /**
     * @param  null|callable(string, ProductCurationAudit): void  $progress
     */
    private function contextPass(ProductCurationAuditRun $run, ?callable $progress): void
    {
        $run->audits()
            ->where('outcome', ProductCurationAuditOutcome::SemanticReady)
            ->orderBy('id')
            ->each(function (ProductCurationAudit $audit) use ($progress): void {
                $started = hrtime(true);

                try {
                    $evidence = ProductCurationEvidence::fromArray($audit->evidence_snapshot);
                    $semantic = $audit->semantic_evaluation;
                    $gift = $this->calculateGiftScore->execute($evidence, $semantic);
                    $context = $this->calculateContext->execute($audit);
                    $differences = $this->buildDifferences->execute($evidence, $semantic);
                    $issues = $this->buildIssues->execute(
                        $evidence,
                        $audit->issues ?? [],
                        $differences,
                        $gift['score'],
                        $context['score'],
                        (string) $semantic['confidence'],
                        $gift['components'],
                        $semantic,
                        $context,
                    );
                    $recommendation = $this->recommend->execute(
                        $gift['score'],
                        $context['score'],
                        $issues,
                        ($semantic['niche_contribution'] ?? 'none') !== 'none',
                    );
                    $review = $this->amendReview->execute(
                        $recommendation,
                        $issues,
                        $gift['score'],
                        $context['score'],
                    );
                    $role = $this->resolveRole->execute(
                        $gift['score'],
                        $context['score'],
                        $semantic,
                        $gift['components'],
                        $context,
                    );
                    $factors = $context['factors'];

                    $audit->update([
                        'outcome' => ProductCurationAuditOutcome::Completed,
                        'gift_score' => $gift['score'],
                        'catalog_value_score' => $context['score'],
                        'gift_score_components' => $gift['components'],
                        'strongest_fits' => $this->strongestFits($semantic),
                        'catalog_role' => $role,
                        'taxonomy_differences' => $differences,
                        'issues' => $issues,
                        'recommendation' => $review['recommendation'],
                        'requires_human_review' => $review['requires_human_review'],
                        'catalog_context_snapshot' => $context['snapshot'],
                        'context_fingerprint' => $context['fingerprint'],
                        'context_calculated_at' => now(),
                        'saturation_novelty_factor' => $factors['saturation_novelty'],
                        'differentiation_factor' => $factors['differentiation'],
                        'budget_gap_factor' => $factors['budget_gap'],
                        'taxonomy_gap_factor' => $factors['taxonomy_gap'],
                        'intents_factor' => $factors['intents'],
                        'niche_factor' => $factors['niche'],
                        'peer_product_ids' => $context['peer_product_ids'],
                        'peer_counts' => $context['peer_counts'],
                        'context_duration_ms' => $this->elapsedMilliseconds($started),
                        'completed_at' => now(),
                        'failure' => null,
                    ]);

                    Log::info('catalog_curation.audit_completed', [
                        'run_id' => $audit->run_id,
                        'audit_id' => $audit->id,
                        'product_id' => $audit->product_id,
                        'gift_score' => $gift['score'],
                        'catalog_value_score' => $context['score'],
                        'recommendation' => $review['recommendation']->value,
                    ]);
                    if ($progress !== null) {
                        $progress('completed', $audit->refresh());
                    }
                } catch (Throwable $exception) {
                    $audit->update([
                        'outcome' => ProductCurationAuditOutcome::Failed,
                        'context_duration_ms' => $this->elapsedMilliseconds($started),
                        'failure' => $exception->getMessage(),
                    ]);
                    Log::warning('catalog_curation.context_failed', [
                        'run_id' => $audit->run_id,
                        'audit_id' => $audit->id,
                        'product_id' => $audit->product_id,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                    if ($progress !== null) {
                        $progress('failed', $audit->refresh());
                    }
                }
            });
    }

    private function reusableSemanticAudit(ProductCurationAudit $audit): ?ProductCurationAudit
    {
        return ProductCurationAudit::query()
            ->where('id', '!=', $audit->id)
            ->where('product_id', $audit->product_id)
            ->where('semantic_fingerprint', $audit->semantic_fingerprint)
            ->where('semantic_evaluator_version', $audit->semantic_evaluator_version)
            ->where('prompt_version', $audit->prompt_version)
            ->whereNotNull('semantic_evaluation')
            ->whereIn('outcome', [
                ProductCurationAuditOutcome::SemanticReady,
                ProductCurationAuditOutcome::Completed,
            ])
            ->latest('semantic_completed_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $semantic
     * @return array<string, array<string, mixed>|null>
     */
    private function strongestFits(array $semantic): array
    {
        $rank = ['strong' => 3, 'medium' => 2, 'weak' => 1];
        $strongest = [];

        foreach (['relationships', 'occasions', 'interests', 'gift_types'] as $dimension) {
            $fits = array_merge(
                $semantic['current_taxonomy_evaluations'][$dimension] ?? [],
                $semantic['taxonomy_suggestions'][$dimension] ?? [],
            );
            usort($fits, fn (array $left, array $right): int => ($rank[$right['strength']] ?? 0) <=> ($rank[$left['strength']] ?? 0));
            $strongest[$dimension] = $fits[0] ?? null;
        }

        return $strongest;
    }

    /**
     * @return array<string, string>
     */
    private function versions(): array
    {
        return [
            'semantic_evaluator_version' => (string) config('catalog_curation.versions.semantic_evaluator'),
            'prompt_version' => (string) config('catalog_curation.versions.prompt'),
            'scoring_version' => (string) config('catalog_curation.versions.scoring'),
            'context_version' => (string) config('catalog_curation.versions.context'),
        ];
    }

    private function elapsedMilliseconds(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }

    private function semanticFingerprint(string $evidenceFingerprint): string
    {
        return hash('sha256', implode('|', [
            $evidenceFingerprint,
            (string) config('catalog_curation.versions.semantic_evaluator'),
            (string) config('catalog_curation.versions.prompt'),
        ]));
    }
}
