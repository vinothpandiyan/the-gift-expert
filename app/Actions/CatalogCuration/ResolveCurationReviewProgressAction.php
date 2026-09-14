<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\ConceptCurationProgress;
use App\CatalogCuration\CurationReviewProgress;
use App\CatalogCuration\HumanCurationQueueItem;
use App\Enums\P3QualitySubgroup;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationPriority;
use App\Models\ProductCurationAuditRun;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;

class ResolveCurationReviewProgressAction
{
    public function __construct(
        private QueryHumanCurationQueueAction $queue,
    ) {}

    public function execute(?ProductCurationAuditRun $run = null): CurationReviewProgress
    {
        $items = $this->queue->items($run);
        $mandatory = $items->filter(fn (HumanCurationQueueItem $item): bool => $item->audit->requires_human_review);
        $decided = $mandatory->filter(fn (HumanCurationQueueItem $item): bool => $this->isReviewed($item));
        $deferred = $mandatory->filter(
            fn (HumanCurationQueueItem $item): bool => $item->decision?->decision === ProductCurationDecision::Defer,
        );

        $decisionCounts = [];

        foreach (ProductCurationDecision::cases() as $decision) {
            $decisionCounts[$decision->value] = $items
                ->filter(fn (HumanCurationQueueItem $item): bool => $item->decision?->decision === $decision)
                ->count();
        }

        $priorityCounts = [];

        foreach (ProductCurationPriority::cases() as $priority) {
            $priorityCounts[$priority->value] = $mandatory
                ->filter(fn (HumanCurationQueueItem $item): bool => $item->priority === $priority && ! $this->isReviewed($item))
                ->count();
        }

        $priorityCounts[ProductCurationPriority::P4->value] = $items
            ->filter(fn (HumanCurationQueueItem $item): bool => $item->priority === ProductCurationPriority::P4)
            ->count();

        $p3SubgroupCounts = [];

        foreach (P3QualitySubgroup::casesInReviewOrder() as $subgroup) {
            $p3SubgroupCounts[$subgroup->value] = $items
                ->filter(fn (HumanCurationQueueItem $item): bool => $item->qualitySubgroup === $subgroup
                    && $item->audit->requires_human_review
                    && ! $this->isReviewed($item))
                ->count();
        }

        $keepFamily = $items->filter(
            fn (HumanCurationQueueItem $item): bool => $item->decision?->decision?->isKeepFamily() === true,
        );
        $featureCount = $keepFamily
            ->filter(fn (HumanCurationQueueItem $item): bool => $item->decision?->decision === ProductCurationDecision::Feature)
            ->count();
        $keepFamilyCount = $keepFamily->count();
        $featureShare = $keepFamilyCount > 0 ? $featureCount / $keepFamilyCount : 0.0;
        $warningShare = (float) config('catalog_curation.human_curation.feature_density_warning_share', 0.8);

        return new CurationReviewProgress(
            mandatoryReview: $mandatory->count(),
            decided: $decided->count(),
            deferred: $deferred->count(),
            remaining: $mandatory->count() - $decided->count(),
            decisionCounts: $decisionCounts,
            priorityCounts: $priorityCounts,
            featureCount: $featureCount,
            keepFamilyCount: $keepFamilyCount,
            featureShare: $featureShare,
            featureDensityWarning: $keepFamilyCount > 0 && $featureShare >= $warningShare,
            p3SubgroupCounts: $p3SubgroupCounts,
        );
    }

    public function forConcept(string $conceptKey, ?ProductCurationAuditRun $run = null): ?ConceptCurationProgress
    {
        if ($conceptKey === '') {
            return null;
        }

        $items = $this->queue->items($run)
            ->filter(fn (HumanCurationQueueItem $item): bool => $item->audit->concept_key === $conceptKey);

        if ($items->isEmpty()) {
            return null;
        }

        $reviewed = $items->filter(fn (HumanCurationQueueItem $item): bool => $this->isReviewed($item))->count();

        return new ConceptCurationProgress(
            key: $conceptKey,
            label: (string) ($items->first()?->audit->concept_label ?: $conceptKey),
            products: $items->count(),
            reviewed: $reviewed,
            remaining: $items->count() - $reviewed,
        );
    }

    /**
     * @return list<ConceptCurationProgress>
     */
    public function clusteredConcepts(?ProductCurationAuditRun $run = null): array
    {
        return $this->queue->items($run)
            ->filter(fn (HumanCurationQueueItem $item): bool => filled($item->audit->concept_key))
            ->groupBy(fn (HumanCurationQueueItem $item): string => (string) $item->audit->concept_key)
            ->filter(fn ($group): bool => $group->count() >= 2)
            ->map(function ($group, string $key): ConceptCurationProgress {
                $reviewed = $group->filter(fn (HumanCurationQueueItem $item): bool => $this->isReviewed($item))->count();

                return new ConceptCurationProgress(
                    key: $key,
                    label: (string) ($group->first()?->audit->concept_label ?: $key),
                    products: $group->count(),
                    reviewed: $reviewed,
                    remaining: $group->count() - $reviewed,
                );
            })
            ->sortByDesc(fn (ConceptCurationProgress $progress): int => $progress->products)
            ->values()
            ->all();
    }

    private function isReviewed(HumanCurationQueueItem $item): bool
    {
        return $item->decision instanceof ProductCurationDecisionRecord && $item->decision->isHumanReviewed();
    }
}
