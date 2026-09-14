<?php

namespace App\Actions\CatalogExpansion;

use App\Enums\IncomingCatalogContribution;
use App\Enums\ProductCurationDecision;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;

class AssessIncomingProductGapContributionAction
{
    /**
     * @param  array<string, mixed>  $coverageHoles
     * @return list<IncomingCatalogContribution>
     */
    public function execute(
        Product $product,
        ?ProductCurationAudit $audit,
        array $coverageHoles = [],
    ): array {
        $contributions = [];
        $assigned = $this->assignedNames($product, $audit);

        foreach ($this->holesByLevel($coverageHoles) as $level => $names) {
            if (! $this->intersects($assigned, $names)) {
                continue;
            }

            $contributions[] = match ($level) {
                'critical' => IncomingCatalogContribution::FillsCriticalGap,
                'high' => IncomingCatalogContribution::FillsHighPriorityGap,
                default => IncomingCatalogContribution::FillsMediumGap,
            };
        }

        if ($this->replacesWeakInventory($product, $audit)) {
            $contributions[] = IncomingCatalogContribution::ReplacesWeakExistingInventory;
        }

        $catalogValue = $audit?->catalog_value_score;
        $keepCatalogValue = (int) config('catalog_curation.thresholds.keep_catalog_value_score', 60);
        $peerCount = is_array($audit?->peer_product_ids)
            ? count($audit->peer_product_ids['concept'] ?? [])
            : 0;

        if ($catalogValue !== null && $catalogValue >= $keepCatalogValue && $peerCount <= 1) {
            $contributions[] = IncomingCatalogContribution::AddsUsefulDifferentiation;
        }

        $contributions = array_values(array_unique($contributions, SORT_REGULAR));

        if ($contributions === []) {
            return [IncomingCatalogContribution::AddsNoMeaningfulCatalogValue];
        }

        if (
            $catalogValue !== null
            && $catalogValue < (int) config('catalog_curation.thresholds.human_review_catalog_value_score', 50)
            && ! in_array(IncomingCatalogContribution::FillsCriticalGap, $contributions, true)
            && ! in_array(IncomingCatalogContribution::FillsHighPriorityGap, $contributions, true)
        ) {
            $contributions[] = IncomingCatalogContribution::AddsNoMeaningfulCatalogValue;
        }

        return array_values(array_unique($contributions, SORT_REGULAR));
    }

    /**
     * @return list<string>
     */
    private function assignedNames(Product $product, ?ProductCurationAudit $audit): array
    {
        $product->loadMissing(['relationships', 'occasions', 'interests', 'giftTypes']);

        $intents = collect($audit?->gift_intents ?? [])
            ->filter(fn (mixed $intent): bool => is_string($intent) && $intent !== '')
            ->all();

        return collect([
            ...$product->relationships->pluck('name'),
            ...$product->occasions->pluck('name'),
            ...$product->interests->pluck('name'),
            ...$product->giftTypes->pluck('name'),
            ...$intents,
        ])
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->map(fn (string $name): string => strtolower($name))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $coverageHoles
     * @return array<string, list<string>>
     */
    private function holesByLevel(array $coverageHoles): array
    {
        $grouped = ['critical' => [], 'high' => [], 'medium' => []];

        foreach (['critical', 'high', 'medium'] as $level) {
            $rows = $coverageHoles[$level] ?? [];

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                $name = is_array($row) ? ($row['name'] ?? $row['label'] ?? null) : $row;

                if (is_string($name) && $name !== '') {
                    $normalized = strtolower($name);
                    $grouped[$level][] = $normalized;
                    $withoutIntentSuffix = preg_replace('/\s+giftintent$/', '', $normalized);

                    if (is_string($withoutIntentSuffix) && $withoutIntentSuffix !== $normalized) {
                        $grouped[$level][] = $withoutIntentSuffix;
                    }
                }
            }
        }

        return $grouped;
    }

    /**
     * @param  list<string>  $assigned
     * @param  list<string>  $names
     */
    private function intersects(array $assigned, array $names): bool
    {
        return array_intersect($assigned, $names) !== [];
    }

    private function replacesWeakInventory(Product $product, ?ProductCurationAudit $audit): bool
    {
        $concept = $audit?->concept_key;

        if (! is_string($concept) || $concept === '') {
            return false;
        }

        $removeIds = ProductCurationDecisionRecord::query()
            ->current()
            ->where('decision', ProductCurationDecision::RemoveCandidate)
            ->pluck('product_id');

        if ($removeIds->isEmpty()) {
            return false;
        }

        return ProductCurationAudit::query()
            ->whereIn('product_id', $removeIds)
            ->where('concept_key', $concept)
            ->where('product_id', '!=', $product->id)
            ->exists();
    }
}
