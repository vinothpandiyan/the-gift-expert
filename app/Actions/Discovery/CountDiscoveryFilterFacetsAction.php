<?php

namespace App\Actions\Discovery;

use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Enums\TaxonomyDimension;
use App\Models\BudgetRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CountDiscoveryFilterFacetsAction
{
    public function __construct(
        private QueryPublishedProductsByFiltersAction $queryProducts,
    ) {}

    /**
     * Disjunctive facet counts for one listing dimension.
     *
     * Applies fixed page context plus user filters from every other dimension.
     * Does not apply this dimension's own user-selected filters.
     *
     * @param  list<int>  $candidateIds
     * @return array<int, int> taxonomy/budget id => distinct published product count
     */
    public function execute(
        DiscoveryListingContext $listing,
        DiscoveryListingQueryState $state,
        string $dimension,
        array $candidateIds,
    ): array {
        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        $candidateIds = array_values(array_filter($candidateIds, fn (int $id): bool => $id > 0));

        if ($candidateIds === []) {
            return [];
        }

        $filters = $state->withoutUserDimension($dimension)->toProductFilters($listing);
        $eligible = $this->queryProducts->execute(
            $filters,
            false,
            ! $this->hasProductFilters($filters),
            true,
        );

        if ($dimension === 'budget') {
            return $this->countBudget($eligible, $candidateIds);
        }

        $taxonomy = TaxonomyDimension::tryFromListingDimension($dimension);

        if ($taxonomy === null) {
            throw new InvalidArgumentException("Unsupported discovery facet dimension [{$dimension}].");
        }

        return $this->countTaxonomy($eligible, $taxonomy, $candidateIds);
    }

    /**
     * @param  list<int>  $candidateIds
     * @return array<int, int>
     */
    private function countTaxonomy(Builder $eligible, TaxonomyDimension $dimension, array $candidateIds): array
    {
        $pivot = $dimension->productPivot();

        $rows = DB::table($pivot['table'])
            ->select($pivot['foreign_key'])
            ->selectRaw('COUNT(DISTINCT product_id) as aggregate')
            ->whereIn($pivot['foreign_key'], $candidateIds)
            ->whereIn('product_id', $eligible->select('products.id'))
            ->groupBy($pivot['foreign_key'])
            ->pluck('aggregate', $pivot['foreign_key']);

        $counts = [];

        foreach ($rows as $id => $count) {
            $counts[(int) $id] = (int) $count;
        }

        return $counts;
    }

    /**
     * @param  list<int>  $candidateIds
     * @return array<int, int>
     */
    private function countBudget(Builder $eligible, array $candidateIds): array
    {
        $ranges = BudgetRange::query()
            ->whereIn('id', $candidateIds)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($ranges->isEmpty()) {
            return [];
        }

        $selects = [];
        $bindings = [];

        foreach ($ranges as $range) {
            [$predicate, $predicateBindings] = $range->priceMatchSql('products.price_amount');
            $match = 'products.price_currency = ? AND products.price_amount IS NOT NULL';

            if ($predicate !== '') {
                $match .= ' AND '.$predicate;
            }

            $selects[] = "SUM(CASE WHEN {$match} THEN 1 ELSE 0 END) as r{$range->id}";
            $bindings[] = $range->currency;
            array_push($bindings, ...$predicateBindings);
        }

        $query = (clone $eligible)->toBase();
        $query->columns = null;
        $query->bindings['select'] = [];

        $row = $query->selectRaw(implode(', ', $selects), $bindings)->first();

        $counts = [];

        foreach ($ranges as $range) {
            $alias = 'r'.$range->id;
            $counts[(int) $range->id] = (int) ($row->{$alias} ?? 0);
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function hasProductFilters(array $filters): bool
    {
        foreach ($filters as $value) {
            if (is_array($value) && $value !== []) {
                return true;
            }

            if (! is_array($value) && $value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }
}
