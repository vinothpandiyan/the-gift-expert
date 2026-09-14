<?php

namespace App\Actions\GapSourcing;

use App\Enums\GapSourcingKind;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductStatus;
use App\GapSourcing\GapSourcingGap;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;
use App\Models\Relationship;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ResolveGenuineCatalogGapsAction
{
    /**
     * @return list<GapSourcingGap>
     */
    public function execute(bool $includeSecondary = false): array
    {
        $definitions = (array) config('gap_sourcing.primary_gaps', []);

        if ($includeSecondary) {
            $definitions = array_merge($definitions, (array) config('gap_sourcing.secondary_gaps', []));
        }

        $gaps = [];

        foreach ($definitions as $key => $definition) {
            $gaps[] = $this->gap((string) $key, is_array($definition) ? $definition : []);
        }

        return $gaps;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function gap(string $key, array $definition): GapSourcingGap
    {
        $names = $this->names($definition['names'] ?? []);
        $dimension = (string) ($definition['dimension'] ?? '');
        $taxonomy = $this->taxonomy($dimension, $names);
        $productIds = $taxonomy instanceof Model
            ? $taxonomy->products()->pluck('products.id')
            : collect();

        $publishedCount = $this->countByStatus($productIds, ProductStatus::Published);
        $draftIds = $this->idsByStatus($productIds, ProductStatus::Draft);
        $keepFamilyDraftCount = $this->keepFamilyCount($draftIds);
        $otherDraftCount = max(0, $draftIds->count() - $keepFamilyDraftCount);

        $kind = match (true) {
            $publishedCount > 0 => GapSourcingKind::Covered,
            $keepFamilyDraftCount > 0 => GapSourcingKind::Publication,
            default => GapSourcingKind::Inventory,
        };

        return new GapSourcingGap(
            key: $key,
            label: (string) ($definition['label'] ?? $key),
            priority: (int) ($definition['priority'] ?? 99),
            dimension: $dimension,
            names: $names,
            publishedCount: $publishedCount,
            keepFamilyDraftCount: $keepFamilyDraftCount,
            otherDraftCount: $otherDraftCount,
            kind: $kind,
        );
    }

    /**
     * @param  list<mixed>  $names
     * @return list<string>
     */
    private function names(array $names): array
    {
        $normalized = [];

        foreach ($names as $name) {
            if (is_string($name) && trim($name) !== '') {
                $normalized[] = trim($name);
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $names
     */
    private function taxonomy(string $dimension, array $names): ?Model
    {
        if ($names === []) {
            return null;
        }

        $query = match ($dimension) {
            'relationships' => Relationship::query(),
            'occasions' => Occasion::query(),
            'interests' => Interest::query(),
            'gift_types' => GiftType::query(),
            default => null,
        };

        if ($query === null) {
            return null;
        }

        return $query->whereIn('name', $names)->first();
    }

    /**
     * @param  Collection<int, mixed>  $productIds
     */
    private function countByStatus(Collection $productIds, ProductStatus $status): int
    {
        if ($productIds->isEmpty()) {
            return 0;
        }

        return Product::query()
            ->whereKey($productIds->all())
            ->where('status', $status)
            ->count();
    }

    /**
     * @param  Collection<int, mixed>  $productIds
     * @return Collection<int, int>
     */
    private function idsByStatus(Collection $productIds, ProductStatus $status): Collection
    {
        if ($productIds->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->whereKey($productIds->all())
            ->where('status', $status)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
    }

    /**
     * @param  Collection<int, int>  $productIds
     */
    private function keepFamilyCount(Collection $productIds): int
    {
        if ($productIds->isEmpty()) {
            return 0;
        }

        $keepFamily = array_map(
            fn (string $value): ProductCurationDecision => ProductCurationDecision::from($value),
            (array) config('gap_sourcing.keep_family_decisions', ['keep', 'feature', 'keep_niche']),
        );

        return ProductCurationDecisionRecord::query()
            ->current()
            ->whereIn('product_id', $productIds->all())
            ->whereIn('decision', $keepFamily)
            ->count();
    }
}
