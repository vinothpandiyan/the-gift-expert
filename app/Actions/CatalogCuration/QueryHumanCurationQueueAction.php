<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\HumanCurationQueueCriteria;
use App\CatalogCuration\HumanCurationQueueItem;
use App\Enums\P3QualitySubgroup;
use App\Enums\ProductCurationAuditOutcome;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationPriority;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;
use Illuminate\Support\Collection;

class QueryHumanCurationQueueAction
{
    /**
     * @var Collection<int, HumanCurationQueueItem>|null
     */
    private ?Collection $index = null;

    public function __construct(
        private ResolveAcceptedCurationAuditRunAction $resolveAcceptedRun,
        private ResolveProductCurationPriorityAction $resolvePriority,
        private ResolveP3QualitySubgroupAction $resolveQualitySubgroup,
    ) {}

    public function acceptedRun(): ?ProductCurationAuditRun
    {
        return $this->resolveAcceptedRun->execute();
    }

    /**
     * @return Collection<int, HumanCurationQueueItem>
     */
    public function items(?ProductCurationAuditRun $run = null): Collection
    {
        $run ??= $this->acceptedRun();

        if (! $run instanceof ProductCurationAuditRun) {
            return collect();
        }

        if ($this->index instanceof Collection) {
            return $this->index;
        }

        $audits = ProductCurationAudit::query()
            ->where('run_id', $run->id)
            ->where('outcome', ProductCurationAuditOutcome::Completed)
            ->with([
                'product.currentCurationDecision.decidedBy',
                'product.images',
                'product.affiliateLinks.merchant',
            ])
            ->get();

        $this->index = $audits
            ->filter(fn (ProductCurationAudit $audit): bool => $audit->product instanceof Product)
            ->map(function (ProductCurationAudit $audit): HumanCurationQueueItem {
                $priority = $this->resolvePriority->execute($audit);

                return new HumanCurationQueueItem(
                    product: $audit->product,
                    audit: $audit,
                    priority: $priority,
                    decision: $audit->product->currentCurationDecision,
                    qualitySubgroup: $priority === ProductCurationPriority::P3
                        ? $this->resolveQualitySubgroup->execute($audit)
                        : null,
                );
            })
            ->values();

        return $this->index;
    }

    /**
     * @return Collection<int, HumanCurationQueueItem>
     */
    public function filtered(?HumanCurationQueueCriteria $criteria = null, ?ProductCurationAuditRun $run = null): Collection
    {
        $criteria ??= new HumanCurationQueueCriteria;
        $items = $this->items($run)->filter(fn (HumanCurationQueueItem $item): bool => $this->matches($item, $criteria));

        $sort = $criteria->sort;

        if ($sort === 'priority' && in_array($criteria->view, ['p1', 'concept_clusters'], true)) {
            $sort = 'concept_cluster';
        }

        if ($sort === 'priority' && in_array($criteria->view, ['p3', 'p3_a', 'p3_b', 'p3_c', 'p3_d', 'p3_e'], true)) {
            $sort = 'quality_cohort';
        }

        return $this->sort($items, $sort)->values();
    }

    /**
     * @return list<int>
     */
    public function productIds(?HumanCurationQueueCriteria $criteria = null, ?ProductCurationAuditRun $run = null): array
    {
        return $this->filtered($criteria, $run)
            ->map(fn (HumanCurationQueueItem $item): int => (int) $item->product->id)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public function viewCounts(?ProductCurationAuditRun $run = null): array
    {
        $views = [
            'needs_review',
            'p0',
            'p1',
            'p2',
            'p3',
            'p3_a',
            'p3_b',
            'p3_c',
            'p3_d',
            'p3_e',
            'p4',
            'concept_clusters',
            'low_gift_score',
            'low_catalog_value',
            'missing_evidence',
            'reviewed',
            'deferred',
            'all',
        ];

        $counts = [];

        foreach ($views as $view) {
            $counts[$view] = $this->filtered(new HumanCurationQueueCriteria(view: $view), $run)->count();
        }

        return $counts;
    }

    public function neighbor(int $productId, string $direction, ?HumanCurationQueueCriteria $criteria = null, ?ProductCurationAuditRun $run = null): ?int
    {
        $ids = $this->productIds($criteria, $run);
        $index = array_search($productId, $ids, true);

        if ($index === false) {
            return null;
        }

        $offset = $direction === 'previous' ? $index - 1 : $index + 1;

        return $ids[$offset] ?? null;
    }

    public function clusterNeighbor(
        int $productId,
        string $direction,
        ?string $conceptKey,
        ?HumanCurationQueueCriteria $criteria = null,
        ?ProductCurationAuditRun $run = null,
    ): ?int {
        if (filled($conceptKey)) {
            $clusterNeighbor = $this->neighbor(
                $productId,
                $direction,
                new HumanCurationQueueCriteria(
                    view: $criteria?->view ?? 'needs_review',
                    conceptKey: $conceptKey,
                    sort: $criteria?->sort === 'priority' ? 'concept_cluster' : ($criteria?->sort ?? 'concept_cluster'),
                ),
                $run,
            );

            if ($clusterNeighbor !== null) {
                return $clusterNeighbor;
            }
        }

        return $this->neighbor($productId, $direction, $criteria, $run);
    }

    private function matches(HumanCurationQueueItem $item, HumanCurationQueueCriteria $criteria): bool
    {
        $audit = $item->audit;
        $decision = $item->decision;
        $reviewed = $decision instanceof ProductCurationDecisionRecord && $decision->isHumanReviewed();
        $deferred = $decision?->decision === ProductCurationDecision::Defer;
        $peerCount = $this->resolvePriority->conceptPeerCount($audit);

        $viewMatch = match ($criteria->view) {
            'needs_review' => $audit->requires_human_review && ! $reviewed,
            'p0' => $item->priority === ProductCurationPriority::P0 && $audit->requires_human_review && ! $reviewed,
            'p1' => $item->priority === ProductCurationPriority::P1 && $audit->requires_human_review && ! $reviewed,
            'p2' => $item->priority === ProductCurationPriority::P2 && $audit->requires_human_review && ! $reviewed,
            'p3' => $item->priority === ProductCurationPriority::P3 && $audit->requires_human_review && ! $reviewed,
            'p3_a' => $this->matchesP3Subgroup($item, $reviewed, P3QualitySubgroup::LowGiftScore),
            'p3_b' => $this->matchesP3Subgroup($item, $reviewed, P3QualitySubgroup::LowCatalogValue),
            'p3_c' => $this->matchesP3Subgroup($item, $reviewed, P3QualitySubgroup::WeakEvidence),
            'p3_d' => $this->matchesP3Subgroup($item, $reviewed, P3QualitySubgroup::LowConfidence),
            'p3_e' => $this->matchesP3Subgroup($item, $reviewed, P3QualitySubgroup::RemainingQuality),
            'p4' => $item->priority === ProductCurationPriority::P4,
            'concept_clusters' => $peerCount >= 1 && $audit->requires_human_review && ! $reviewed,
            'low_gift_score' => $audit->gift_score !== null
                && $audit->gift_score < (int) config('catalog_curation.thresholds.human_review_gift_score', 65)
                && ! $reviewed,
            'low_catalog_value' => $audit->catalog_value_score !== null
                && $audit->catalog_value_score < (int) config('catalog_curation.thresholds.human_review_catalog_value_score', 50)
                && ! $reviewed,
            'missing_evidence' => $this->resolvePriority->hasEvidenceIssue($audit) && ! $reviewed,
            'reviewed' => $reviewed,
            'deferred' => $deferred,
            'all' => true,
            default => $audit->requires_human_review && ! $reviewed,
        };

        if (! $viewMatch) {
            return false;
        }

        if ($criteria->priority instanceof ProductCurationPriority && $item->priority !== $criteria->priority) {
            return false;
        }

        if ($criteria->requiresHumanReview !== null && $audit->requires_human_review !== $criteria->requiresHumanReview) {
            return false;
        }

        if ($criteria->humanDecision instanceof ProductCurationDecision) {
            if ($decision?->decision !== $criteria->humanDecision) {
                return false;
            }
        }

        if ($criteria->recommendation !== null && $audit->recommendation !== $criteria->recommendation) {
            return false;
        }

        if ($criteria->giftScoreMin !== null && (int) $audit->gift_score < $criteria->giftScoreMin) {
            return false;
        }

        if ($criteria->giftScoreMax !== null && (int) $audit->gift_score > $criteria->giftScoreMax) {
            return false;
        }

        if ($criteria->catalogValueMin !== null && (int) $audit->catalog_value_score < $criteria->catalogValueMin) {
            return false;
        }

        if ($criteria->catalogValueMax !== null && (int) $audit->catalog_value_score > $criteria->catalogValueMax) {
            return false;
        }

        if ($criteria->conceptKey !== null && $audit->concept_key !== $criteria->conceptKey) {
            return false;
        }

        if ($criteria->hasConceptPeers !== null && ($peerCount >= 1) !== $criteria->hasConceptPeers) {
            return false;
        }

        if ($criteria->relationshipId !== null && ! $this->hasTaxonomyId($audit, 'relationships', $criteria->relationshipId)) {
            return false;
        }

        if ($criteria->occasionId !== null && ! $this->hasTaxonomyId($audit, 'occasions', $criteria->occasionId)) {
            return false;
        }

        if ($criteria->interestId !== null && ! $this->hasTaxonomyId($audit, 'interests', $criteria->interestId)) {
            return false;
        }

        if ($criteria->giftTypeId !== null && ! $this->hasTaxonomyId($audit, 'gift_types', $criteria->giftTypeId)) {
            return false;
        }

        if ($criteria->budgetBand !== null && data_get($audit->catalog_context_snapshot, 'price_band') !== $criteria->budgetBand) {
            return false;
        }

        if ($criteria->giftIntent !== null && ! in_array($criteria->giftIntent, (array) $audit->gift_intents, true)) {
            return false;
        }

        if ($criteria->taxonomySeverity !== null && $this->resolvePriority->taxonomySeverity($audit) !== $criteria->taxonomySeverity) {
            return false;
        }

        if ($criteria->hasEvidenceIssue !== null && $this->resolvePriority->hasEvidenceIssue($audit) !== $criteria->hasEvidenceIssue) {
            return false;
        }

        if ($criteria->aiConfidence !== null && $audit->ai_confidence !== $criteria->aiConfidence) {
            return false;
        }

        if ($criteria->status !== null && $item->product->status !== $criteria->status) {
            return false;
        }

        if ($criteria->qualitySubgroup instanceof P3QualitySubgroup && $item->qualitySubgroup !== $criteria->qualitySubgroup) {
            return false;
        }

        return true;
    }

    private function matchesP3Subgroup(HumanCurationQueueItem $item, bool $reviewed, P3QualitySubgroup $subgroup): bool
    {
        return $item->priority === ProductCurationPriority::P3
            && $item->qualitySubgroup === $subgroup
            && $item->audit->requires_human_review
            && ! $reviewed;
    }

    /**
     * @param  Collection<int, HumanCurationQueueItem>  $items
     * @return Collection<int, HumanCurationQueueItem>
     */
    public function flush(): void
    {
        $this->index = null;
    }

    private function sort(Collection $items, string $sort): Collection
    {
        return match ($sort) {
            'gift_score_asc' => $items->sortBy(
                fn (HumanCurationQueueItem $item): array => [
                    (int) $item->audit->gift_score,
                    (int) $item->product->id,
                ],
            ),
            'catalog_value_asc' => $items->sortBy(
                fn (HumanCurationQueueItem $item): array => [
                    (int) $item->audit->catalog_value_score,
                    (int) $item->product->id,
                ],
            ),
            'catalog_value_desc' => $items->sortBy(
                fn (HumanCurationQueueItem $item): array => [
                    -1 * (int) $item->audit->catalog_value_score,
                    (int) $item->product->id,
                ],
            ),
            'concept_peer_count_desc' => $items->sortBy(
                fn (HumanCurationQueueItem $item): array => [
                    -1 * $this->resolvePriority->conceptPeerCount($item->audit),
                    (int) $item->product->id,
                ],
            ),
            'product_id' => $items->sortBy(fn (HumanCurationQueueItem $item): int => (int) $item->product->id),
            'concept_cluster' => $items->sortBy(
                fn (HumanCurationQueueItem $item): array => [
                    -1 * $this->resolvePriority->conceptPeerCount($item->audit),
                    (string) ($item->audit->concept_key ?? ''),
                    (int) $item->audit->catalog_value_score,
                    (int) $item->audit->gift_score,
                    (int) $item->product->id,
                ],
            ),
            'quality_cohort' => $items->sortBy(
                fn (HumanCurationQueueItem $item): array => [
                    $item->qualitySubgroup?->rank() ?? 99,
                    (int) $item->audit->gift_score,
                    (int) $item->audit->catalog_value_score,
                    (int) $item->product->id,
                ],
            ),
            default => $items->sortBy(
                fn (HumanCurationQueueItem $item): array => [
                    $item->priority->rank(),
                    (int) $item->audit->catalog_value_score,
                    (int) $item->audit->gift_score,
                    (int) $item->product->id,
                ],
            ),
        };
    }

    private function hasTaxonomyId(ProductCurationAudit $audit, string $dimension, int $id): bool
    {
        $current = data_get($audit->evidence_snapshot, "taxonomy.{$dimension}", []);

        if (! is_array($current)) {
            return false;
        }

        return collect($current)->contains(
            fn (mixed $item): bool => is_array($item) && (int) ($item['id'] ?? 0) === $id,
        );
    }
}
