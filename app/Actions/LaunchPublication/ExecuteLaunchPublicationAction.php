<?php

namespace App\Actions\LaunchPublication;

use App\Actions\Product\PublishProductsAction;
use App\Enums\ProductCurationDecision;
use App\LaunchPublication\LaunchPublicationAttempt;
use App\Models\Product;

class ExecuteLaunchPublicationAction
{
    public function __construct(
        private CaptureLaunchCatalogFingerprintAction $captureFingerprint,
        private PublishLaunchCatalogCohortAction $publishCohort,
        private ReconcileLaunchCatalogFingerprintAction $reconcileFingerprint,
        private BuildLaunchCatalogCoverageAction $buildCoverage,
        private BuildLaunchGapBriefAction $buildGapBrief,
        private InspectPublishedCatalogAction $inspectPublished,
    ) {}

    /**
     * @param  list<int>  $productIds
     * @param  array<string, mixed>  $readiness
     * @return array<string, mixed>
     */
    public function execute(array $productIds, array $readiness, string $actor = 'console:catalog:launch-publication'): array
    {
        $before = $this->captureFingerprint->execute();
        $attempts = $this->publishCohort->execute($productIds, $actor);
        $after = $this->captureFingerprint->execute();
        $reconciliation = $this->reconcileFingerprint->execute($before, $after, $attempts->all());

        $publishedIds = Product::query()
            ->published()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $coverage = $this->buildCoverage->execute($publishedIds);
        $brief = $this->buildGapBrief->execute($readiness, $coverage);
        $quality = $this->qualityBreakdown();

        return [
            'before' => $before->toArray(),
            'after' => $after->toArray(),
            'reconciliation' => $reconciliation,
            'attempts' => $attempts->map(fn (LaunchPublicationAttempt $attempt): array => $attempt->toArray())->all(),
            'tally' => PublishProductsAction::tally($attempts),
            'catalog_counts' => $after->counts,
            'coverage' => $coverage,
            'gap_brief' => $brief,
            'quality' => $quality,
            'published_inspection' => $this->inspectPublished->execute(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function qualityBreakdown(): array
    {
        $published = Product::query()
            ->published()
            ->with('currentCurationDecision')
            ->orderBy('id')
            ->get();

        $counts = [
            ProductCurationDecision::Feature->value => 0,
            ProductCurationDecision::Keep->value => 0,
            ProductCurationDecision::KeepNiche->value => 0,
            'legacy_other' => 0,
        ];
        $flags = [];

        foreach ($published as $product) {
            $decision = $product->currentCurationDecision?->decision;
            $value = $decision?->value;

            if ($decision instanceof ProductCurationDecision && $decision->isKeepFamily()) {
                $counts[$value]++;
            } else {
                $counts['legacy_other']++;
            }

            if (in_array($value, [
                ProductCurationDecision::RemoveCandidate->value,
                ProductCurationDecision::Defer->value,
                ProductCurationDecision::Deactivate->value,
            ], true)) {
                $flags[] = [
                    'product_id' => (int) $product->id,
                    'title' => (string) $product->name,
                    'human_decision' => $value,
                ];
            }
        }

        return [
            'human_feature' => $counts[ProductCurationDecision::Feature->value],
            'human_keep' => $counts[ProductCurationDecision::Keep->value],
            'human_keep_niche' => $counts[ProductCurationDecision::KeepNiche->value],
            'legacy_other_published' => $counts['legacy_other'],
            'flagged_decisions' => $flags,
        ];
    }
}
