<?php

namespace App\Actions\Catalog;

use App\CatalogCoverage\AutomationReadinessSummary;
use App\CatalogCoverage\BudgetRangeCoverage;
use App\CatalogCoverage\CatalogCoverageOptions;
use App\CatalogCoverage\CatalogCoverageReport;
use App\CatalogCoverage\CompositeCoverage;
use App\CatalogCoverage\CoverageGap;
use App\CatalogCoverage\DimensionCoverage;
use App\CatalogCoverage\MissingTaxonomySummary;
use App\Enums\CoverageGapSeverity;
use App\Enums\ProductAutomationReadiness;
use App\Enums\ProductStatus;
use App\Models\BudgetRange;
use App\Models\CatalogCandidateSourcingItem;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyzeCatalogCoverageAction
{
    /**
     * @var list<string>
     */
    private const DIMENSION_KEYS = [
        'relationship',
        'occasion',
        'category',
        'recipient_type',
        'interest',
        'profession',
        'gift_type',
    ];

    public function execute(CatalogCoverageOptions $options): CatalogCoverageReport
    {
        $population = $this->loadPopulation($options);
        $populationIds = $population->pluck('id')->all();
        $populationById = $population->keyBy('id');

        $automationDraftIds = $this->resolveAutomationDraftProductIds($populationById);
        $budgetRanges = $this->loadActiveBudgetRanges();
        $budgetMap = $this->mapProductsToBudgetRanges($population, $budgetRanges);

        $pivotMaps = $this->loadPivotMaps($populationIds);
        $taxonomyCatalog = $this->loadActiveTaxonomyCatalog();

        $publishedCount = $population->where('status', ProductStatus::Published->value)->count();
        $automationDraftCount = $automationDraftIds->count();
        $unpricedCount = $population->filter(fn (object $product): bool => $this->isUnpriced($product))->count();

        $budgetCoverage = $this->aggregateBudgetCoverage(
            population: $population,
            automationDraftIds: $automationDraftIds,
            budgetRanges: $budgetRanges,
            budgetMap: $budgetMap,
        );

        $dimensionCoverage = $this->aggregateDimensionCoverage(
            population: $population,
            automationDraftIds: $automationDraftIds,
            pivotMaps: $pivotMaps,
            taxonomyCatalog: $taxonomyCatalog,
            dimensionFilter: $options->dimensionFilter,
        );

        $compositeCoverage = $this->aggregateCompositeCoverage(
            population: $population,
            automationDraftIds: $automationDraftIds,
            budgetMap: $budgetMap,
            pivotMaps: $pivotMaps,
            taxonomyCatalog: $taxonomyCatalog,
            budgetRanges: $budgetRanges,
        );

        $missingTaxonomy = $this->summarizeMissingTaxonomies($populationIds, $pivotMaps);
        $readiness = $this->summarizeReadiness($automationDraftIds);

        $gaps = $this->deriveGaps(
            budgetCoverage: $budgetCoverage,
            dimensionCoverage: $dimensionCoverage,
            compositeCoverage: $compositeCoverage,
            missingTaxonomy: $missingTaxonomy,
            unpricedCount: $unpricedCount,
            populationTotal: $population->count(),
        );

        return new CatalogCoverageReport(
            generatedAt: Carbon::now(),
            totalProducts: $population->count(),
            publishedProducts: $publishedCount,
            automationDraftProducts: $automationDraftCount,
            unpricedCount: $unpricedCount,
            missingTaxonomy: $missingTaxonomy,
            readiness: $readiness,
            budgetCoverage: $budgetCoverage,
            dimensionCoverage: $dimensionCoverage,
            compositeCoverage: $compositeCoverage,
            gaps: $gaps,
        );
    }

    /**
     * @return Collection<int, object{id: int, status: string, price_amount: ?string, price_currency: ?string}>
     */
    private function loadPopulation(CatalogCoverageOptions $options): Collection
    {
        return $this->buildPopulationQuery($options)
            ->select(['id', 'status', 'price_amount', 'price_currency'])
            ->orderBy('id')
            ->get()
            ->map(fn (Product $product): object => (object) [
                'id' => $product->id,
                'status' => $product->status->value,
                'price_amount' => $product->price_amount !== null ? (string) $product->price_amount : null,
                'price_currency' => $product->price_currency,
            ]);
    }

    private function buildPopulationQuery(CatalogCoverageOptions $options): Builder
    {
        $query = Product::query()
            ->whereNull('deleted_at')
            ->where('status', '!=', ProductStatus::Archived);

        if ($options->publishedOnly) {
            return $query->where('status', ProductStatus::Published);
        }

        if ($options->includeManualDrafts) {
            return $query->where(function (Builder $builder): void {
                $builder->where('status', ProductStatus::Published)
                    ->orWhere('status', ProductStatus::Draft);
            });
        }

        return $query->where(function (Builder $builder): void {
            $builder->where('status', ProductStatus::Published)
                ->orWhere(function (Builder $draftQuery): void {
                    $draftQuery->where('status', ProductStatus::Draft)
                        ->whereHas('sourcingItems', fn (Builder $sourcingQuery): Builder => $sourcingQuery->whereNotNull('product_id'));
                });
        });
    }

    /**
     * @param  Collection<int, object{id: int, status: string, price_amount: ?string, price_currency: ?string}>  $populationById
     * @return Collection<int, int>
     */
    private function resolveAutomationDraftProductIds(Collection $populationById): Collection
    {
        if ($populationById->isEmpty()) {
            return collect();
        }

        return CatalogCandidateSourcingItem::query()
            ->whereNotNull('product_id')
            ->whereIn('product_id', $populationById->keys())
            ->distinct()
            ->pluck('product_id')
            ->filter(function (int $productId) use ($populationById): bool {
                $product = $populationById->get($productId);

                return $product !== null && $product->status === ProductStatus::Draft->value;
            })
            ->values();
    }

    /**
     * @return Collection<int, BudgetRange>
     */
    private function loadActiveBudgetRanges(): Collection
    {
        return BudgetRange::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, object{id: int, status: string, price_amount: ?string, price_currency: ?string}>  $population
     * @param  Collection<int, BudgetRange>  $budgetRanges
     * @return array<int, int|null> productId => budgetRangeId
     */
    private function mapProductsToBudgetRanges(Collection $population, Collection $budgetRanges): array
    {
        $map = [];

        foreach ($population as $product) {
            $map[$product->id] = $this->mapPriceToBudgetRangeId(
                $product->price_amount,
                $product->price_currency,
                $budgetRanges,
            );
        }

        return $map;
    }

    /**
     * @param  Collection<int, BudgetRange>  $budgetRanges
     */
    private function mapPriceToBudgetRangeId(?string $priceAmount, ?string $priceCurrency, Collection $budgetRanges): ?int
    {
        if ($priceAmount === null || $priceCurrency === null || ! is_numeric($priceAmount)) {
            return null;
        }

        $currency = strtoupper(trim($priceCurrency));

        if ($currency === '') {
            return null;
        }

        $amount = (float) $priceAmount;

        foreach ($budgetRanges as $range) {
            if (strtoupper($range->currency) !== $currency) {
                continue;
            }

            if ($range->min_amount !== null && $amount < (float) $range->min_amount) {
                continue;
            }

            if ($range->max_amount !== null && $amount > (float) $range->max_amount) {
                continue;
            }

            return $range->id;
        }

        return null;
    }

    /**
     * @param  list<int>  $populationIds
     * @return array{
     *     relationship: array<int, list<int>>,
     *     occasion: array<int, list<int>>,
     *     category: array<int, list<int>>,
     *     primary_category: array<int, int|null>,
     *     recipient_type: array<int, list<int>>,
     *     interest: array<int, list<int>>,
     *     profession: array<int, list<int>>,
     *     gift_type: array<int, list<int>>,
     * }
     */
    private function loadPivotMaps(array $populationIds): array
    {
        if ($populationIds === []) {
            return [
                'relationship' => [],
                'occasion' => [],
                'category' => [],
                'primary_category' => [],
                'recipient_type' => [],
                'interest' => [],
                'profession' => [],
                'gift_type' => [],
            ];
        }

        return [
            'relationship' => $this->loadSimplePivotMap('relationship_product', 'relationship_id', $populationIds),
            'occasion' => $this->loadSimplePivotMap('occasion_product', 'occasion_id', $populationIds),
            'category' => $this->loadSimplePivotMap('category_product', 'category_id', $populationIds),
            'primary_category' => $this->loadPrimaryCategoryMap($populationIds),
            'recipient_type' => $this->loadSimplePivotMap('recipient_type_product', 'recipient_type_id', $populationIds),
            'interest' => $this->loadSimplePivotMap('interest_product', 'interest_id', $populationIds),
            'profession' => $this->loadSimplePivotMap('profession_product', 'profession_id', $populationIds),
            'gift_type' => $this->loadSimplePivotMap('gift_type_product', 'gift_type_id', $populationIds),
        ];
    }

    /**
     * @param  list<int>  $populationIds
     * @return array<int, list<int>>
     */
    private function loadSimplePivotMap(string $table, string $taxonomyColumn, array $populationIds): array
    {
        $map = [];

        $rows = DB::table($table)
            ->whereIn('product_id', $populationIds)
            ->select(['product_id', $taxonomyColumn])
            ->get();

        foreach ($rows as $row) {
            $map[(int) $row->product_id][] = (int) $row->{$taxonomyColumn};
        }

        return $map;
    }

    /**
     * @param  list<int>  $populationIds
     * @return array<int, int|null>
     */
    private function loadPrimaryCategoryMap(array $populationIds): array
    {
        $map = [];

        $rows = DB::table('category_product')
            ->whereIn('product_id', $populationIds)
            ->where('is_primary', 1)
            ->select(['product_id', 'category_id'])
            ->get();

        foreach ($rows as $row) {
            $map[(int) $row->product_id] = (int) $row->category_id;
        }

        return $map;
    }

    /**
     * @return array<string, Collection<int, object{id: int, slug: string, name: string}>>
     */
    private function loadActiveTaxonomyCatalog(): array
    {
        return [
            'relationship' => $this->loadActiveTaxonomyRows(Relationship::class),
            'occasion' => $this->loadActiveTaxonomyRows(Occasion::class),
            'category' => $this->loadActiveTaxonomyRows(Category::class),
            'recipient_type' => $this->loadActiveTaxonomyRows(RecipientType::class),
            'interest' => $this->loadActiveTaxonomyRows(Interest::class),
            'profession' => $this->loadActiveTaxonomyRows(Profession::class),
            'gift_type' => $this->loadActiveTaxonomyRows(GiftType::class),
            'budget_range' => $this->loadActiveBudgetRanges()->map(fn (BudgetRange $range): object => (object) [
                'id' => $range->id,
                'slug' => $range->slug,
                'name' => $range->name,
            ]),
        ];
    }

    /**
     * @param  class-string  $modelClass
     * @return Collection<int, object{id: int, slug: string, name: string}>
     */
    private function loadActiveTaxonomyRows(string $modelClass): Collection
    {
        return $modelClass::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'slug', 'name'])
            ->map(fn ($row): object => (object) [
                'id' => $row->id,
                'slug' => $row->slug,
                'name' => $row->name,
            ]);
    }

    /**
     * @param  Collection<int, object{id: int, status: string, price_amount: ?string, price_currency: ?string}>  $population
     * @param  Collection<int, int>  $automationDraftIds
     * @param  Collection<int, BudgetRange>  $budgetRanges
     * @param  array<int, int|null>  $budgetMap
     * @return list<BudgetRangeCoverage>
     */
    private function aggregateBudgetCoverage(
        Collection $population,
        Collection $automationDraftIds,
        Collection $budgetRanges,
        array $budgetMap,
    ): array {
        $pricedPopulation = $population->reject(fn (object $product): bool => $this->isUnpriced($product));
        $pricedTotal = $pricedPopulation->count();
        $automationDraftSet = $automationDraftIds->flip();
        $targets = config('catalog_coverage.budget_target_percentages', []);
        $tolerance = (float) config('catalog_coverage.target_tolerance_percent', 0);

        $counts = [];

        foreach ($budgetRanges as $range) {
            $counts[$range->id] = [
                'product' => 0,
                'published' => 0,
                'automation_draft' => 0,
            ];
        }

        foreach ($pricedPopulation as $product) {
            $rangeId = $budgetMap[$product->id] ?? null;

            if ($rangeId === null || ! isset($counts[$rangeId])) {
                continue;
            }

            $counts[$rangeId]['product']++;

            if ($product->status === ProductStatus::Published->value) {
                $counts[$rangeId]['published']++;
            } elseif ($automationDraftSet->has($product->id)) {
                $counts[$rangeId]['automation_draft']++;
            }
        }

        $coverage = [];

        foreach ($budgetRanges as $range) {
            $productCount = $counts[$range->id]['product'];
            $percentage = $pricedTotal > 0 ? round(($productCount / $pricedTotal) * 100, 2) : 0.0;
            $targetShare = isset($targets[$range->slug]) ? (float) $targets[$range->slug] : null;
            $delta = $targetShare !== null ? round($percentage - $targetShare, 2) : null;
            $severity = $this->resolveCountSeverity(
                count: $productCount,
                minimumCount: (int) config('catalog_coverage.gaps.minimum_count', 3),
                percentage: $percentage,
                targetShare: $targetShare,
                tolerance: $tolerance,
            );

            $coverage[] = new BudgetRangeCoverage(
                id: $range->id,
                slug: $range->slug,
                name: $range->name,
                productCount: $productCount,
                publishedCount: $counts[$range->id]['published'],
                automationDraftCount: $counts[$range->id]['automation_draft'],
                percentageOfTotal: $percentage,
                targetSharePercent: $targetShare,
                deltaFromTargetPercent: $delta,
                severity: $severity,
            );
        }

        return $coverage;
    }

    /**
     * @param  Collection<int, object{id: int, status: string, price_amount: ?string, price_currency: ?string}>  $population
     * @param  Collection<int, int>  $automationDraftIds
     * @param  array<string, mixed>  $pivotMaps
     * @param  array<string, Collection<int, object{id: int, slug: string, name: string}>>  $taxonomyCatalog
     * @return list<DimensionCoverage>
     */
    private function aggregateDimensionCoverage(
        Collection $population,
        Collection $automationDraftIds,
        array $pivotMaps,
        array $taxonomyCatalog,
        ?string $dimensionFilter,
    ): array {
        $coverage = [];
        $automationDraftSet = $automationDraftIds->flip();

        foreach (self::DIMENSION_KEYS as $dimension) {
            if ($dimensionFilter !== null && $dimensionFilter !== $dimension) {
                continue;
            }

            $pivotKey = $dimension;
            $productTags = $pivotMaps[$pivotKey] ?? [];

            foreach ($taxonomyCatalog[$dimension] as $taxonomy) {
                $productCount = 0;
                $publishedCount = 0;
                $automationDraftCount = 0;

                foreach ($population as $product) {
                    $tagIds = $productTags[$product->id] ?? [];

                    if (! in_array($taxonomy->id, $tagIds, true)) {
                        continue;
                    }

                    $productCount++;

                    if ($product->status === ProductStatus::Published->value) {
                        $publishedCount++;
                    } elseif ($automationDraftSet->has($product->id)) {
                        $automationDraftCount++;
                    }
                }

                $coverage[] = new DimensionCoverage(
                    dimension: $dimension,
                    id: $taxonomy->id,
                    slug: $taxonomy->slug,
                    name: $taxonomy->name,
                    productCount: $productCount,
                    publishedCount: $publishedCount,
                    automationDraftCount: $automationDraftCount,
                );
            }
        }

        return $coverage;
    }

    /**
     * @param  Collection<int, object{id: int, status: string, price_amount: ?string, price_currency: ?string}>  $population
     * @param  Collection<int, int>  $automationDraftIds
     * @param  array<int, int|null>  $budgetMap
     * @param  array<string, mixed>  $pivotMaps
     * @param  array<string, Collection<int, object{id: int, slug: string, name: string}>>  $taxonomyCatalog
     * @param  Collection<int, BudgetRange>  $budgetRanges
     * @return list<CompositeCoverage>
     */
    private function aggregateCompositeCoverage(
        Collection $population,
        Collection $automationDraftIds,
        array $budgetMap,
        array $pivotMaps,
        array $taxonomyCatalog,
        Collection $budgetRanges,
    ): array {
        $definitions = config('catalog_coverage.composites', []);
        $automationDraftSet = $automationDraftIds->flip();
        $budgetById = $budgetRanges->keyBy('id');
        $cells = [];

        foreach ($definitions as $definition) {
            $dimensions = $definition['dimensions'] ?? [];
            $compositeKey = (string) ($definition['key'] ?? implode('_', $dimensions));

            foreach ($this->compositeCellsForDefinition($dimensions, $taxonomyCatalog, $budgetById) as $cellKey => $cellMeta) {
                $cells[$compositeKey][$cellKey] = [
                    'label' => $cellMeta['label'],
                    'dimension_values' => $cellMeta['dimension_values'],
                    'product' => 0,
                    'published' => 0,
                    'automation_draft' => 0,
                ];
            }
        }

        foreach ($population as $product) {
            $dimensionValuesList = $this->dimensionValueSetsForProduct(
                productId: $product->id,
                pivotMaps: $pivotMaps,
                budgetMap: $budgetMap,
                taxonomyCatalog: $taxonomyCatalog,
            );

            foreach ($definitions as $definition) {
                $dimensions = $definition['dimensions'] ?? [];
                $compositeKey = (string) ($definition['key'] ?? implode('_', $dimensions));
                $combos = $this->combineDimensionValues($dimensions, $dimensionValuesList);

                foreach ($combos as $combo) {
                    $cellKey = $this->compositeCellKey($combo);

                    if (! isset($cells[$compositeKey][$cellKey])) {
                        continue;
                    }

                    $cells[$compositeKey][$cellKey]['product']++;

                    if ($product->status === ProductStatus::Published->value) {
                        $cells[$compositeKey][$cellKey]['published']++;
                    } elseif ($automationDraftSet->has($product->id)) {
                        $cells[$compositeKey][$cellKey]['automation_draft']++;
                    }
                }
            }
        }

        $coverage = [];

        foreach ($cells as $compositeKey => $compositeCells) {
            foreach ($compositeCells as $cell) {
                $coverage[] = new CompositeCoverage(
                    compositeKey: $compositeKey,
                    label: $cell['label'],
                    dimensionValues: $cell['dimension_values'],
                    productCount: $cell['product'],
                    publishedCount: $cell['published'],
                    automationDraftCount: $cell['automation_draft'],
                );
            }
        }

        return $coverage;
    }

    /**
     * @param  list<string>  $dimensions
     * @param  array<string, Collection<int, object{id: int, slug: string, name: string}>>  $taxonomyCatalog
     * @param  Collection<int, BudgetRange>  $budgetById
     * @return array<string, array{label: string, dimension_values: array<string, string>}>
     */
    private function compositeCellsForDefinition(array $dimensions, array $taxonomyCatalog, Collection $budgetById): array
    {
        $sets = [];

        foreach ($dimensions as $dimension) {
            if ($dimension === 'budget_range') {
                $sets[] = $taxonomyCatalog['budget_range']->map(fn (object $row): array => [
                    'dimension' => 'budget_range',
                    'slug' => $row->slug,
                    'name' => $row->name,
                ])->all();

                continue;
            }

            $sets[] = ($taxonomyCatalog[$dimension] ?? collect())->map(fn (object $row): array => [
                'dimension' => $dimension,
                'slug' => $row->slug,
                'name' => $row->name,
            ])->all();
        }

        $cells = [];
        $this->buildCompositeCellMatrix($sets, 0, [], $cells);

        return $cells;
    }

    /**
     * @param  list<list<array{dimension: string, slug: string, name: string}>>  $sets
     * @param  list<array{dimension: string, slug: string, name: string}>  $current
     * @param  array<string, array{label: string, dimension_values: array<string, string>}>  $cells
     */
    private function buildCompositeCellMatrix(array $sets, int $index, array $current, array &$cells): void
    {
        if ($index >= count($sets)) {
            $dimensionValues = [];
            $labelParts = [];

            foreach ($current as $entry) {
                $dimensionValues[$entry['dimension']] = $entry['slug'];
                $labelParts[] = $entry['name'];
            }

            $cellKey = $this->compositeCellKey($dimensionValues);
            $cells[$cellKey] = [
                'label' => implode(' × ', $labelParts),
                'dimension_values' => $dimensionValues,
            ];

            return;
        }

        foreach ($sets[$index] as $entry) {
            $this->buildCompositeCellMatrix($sets, $index + 1, [...$current, $entry], $cells);
        }
    }

    /**
     * @param  array<string, mixed>  $pivotMaps
     * @param  array<string, Collection<int, object{id: int, slug: string, name: string}>>  $taxonomyCatalog
     * @return array<string, list<string>>
     */
    private function dimensionValueSetsForProduct(
        int $productId,
        array $pivotMaps,
        array $budgetMap,
        array $taxonomyCatalog,
    ): array {
        $slugByDimensionAndId = [];

        foreach (self::DIMENSION_KEYS as $dimension) {
            foreach ($taxonomyCatalog[$dimension] as $row) {
                $slugByDimensionAndId[$dimension][$row->id] = $row->slug;
            }
        }

        foreach ($taxonomyCatalog['budget_range'] as $row) {
            $slugByDimensionAndId['budget_range'][$row->id] = $row->slug;
        }

        $values = [
            'relationship' => array_values(array_map(
                fn (int $id): string => $slugByDimensionAndId['relationship'][$id] ?? (string) $id,
                $pivotMaps['relationship'][$productId] ?? [],
            )),
            'occasion' => array_values(array_map(
                fn (int $id): string => $slugByDimensionAndId['occasion'][$id] ?? (string) $id,
                $pivotMaps['occasion'][$productId] ?? [],
            )),
            'category' => array_values(array_map(
                fn (int $id): string => $slugByDimensionAndId['category'][$id] ?? (string) $id,
                $pivotMaps['category'][$productId] ?? [],
            )),
            'primary_category' => [],
            'recipient_type' => array_values(array_map(
                fn (int $id): string => $slugByDimensionAndId['recipient_type'][$id] ?? (string) $id,
                $pivotMaps['recipient_type'][$productId] ?? [],
            )),
            'interest' => array_values(array_map(
                fn (int $id): string => $slugByDimensionAndId['interest'][$id] ?? (string) $id,
                $pivotMaps['interest'][$productId] ?? [],
            )),
            'profession' => array_values(array_map(
                fn (int $id): string => $slugByDimensionAndId['profession'][$id] ?? (string) $id,
                $pivotMaps['profession'][$productId] ?? [],
            )),
            'gift_type' => array_values(array_map(
                fn (int $id): string => $slugByDimensionAndId['gift_type'][$id] ?? (string) $id,
                $pivotMaps['gift_type'][$productId] ?? [],
            )),
            'budget_range' => [],
        ];

        $primaryCategoryId = $pivotMaps['primary_category'][$productId] ?? null;

        if ($primaryCategoryId !== null) {
            $values['primary_category'] = [
                $slugByDimensionAndId['category'][$primaryCategoryId] ?? (string) $primaryCategoryId,
            ];
        }

        $budgetRangeId = $budgetMap[$productId] ?? null;

        if ($budgetRangeId !== null) {
            $values['budget_range'] = [
                $slugByDimensionAndId['budget_range'][$budgetRangeId] ?? (string) $budgetRangeId,
            ];
        }

        return $values;
    }

    /**
     * @param  list<string>  $dimensions
     * @param  array<string, list<string>>  $dimensionValuesList
     * @return list<array<string, string>>
     */
    private function combineDimensionValues(array $dimensions, array $dimensionValuesList): array
    {
        $combos = [[]];

        foreach ($dimensions as $dimension) {
            $sourceDimension = $dimension === 'category' ? 'primary_category' : $dimension;
            $values = $dimensionValuesList[$sourceDimension] ?? [];

            if ($values === []) {
                return [];
            }

            $next = [];

            foreach ($combos as $combo) {
                foreach ($values as $value) {
                    $next[] = [...$combo, $dimension => $value];
                }
            }

            $combos = $next;
        }

        return $combos;
    }

    /**
     * @param  array<string, string>  $dimensionValues
     */
    private function compositeCellKey(array $dimensionValues): string
    {
        ksort($dimensionValues);

        return implode('|', array_map(
            fn (string $dimension, string $slug): string => $dimension.':'.$slug,
            array_keys($dimensionValues),
            array_values($dimensionValues),
        ));
    }

    /**
     * @param  list<int>  $populationIds
     * @param  array<string, mixed>  $pivotMaps
     */
    private function summarizeMissingTaxonomies(array $populationIds, array $pivotMaps): MissingTaxonomySummary
    {
        $counts = [
            'no_primary_category' => 0,
            'no_category' => 0,
            'no_relationship' => 0,
            'no_occasion' => 0,
            'no_recipient_type' => 0,
            'no_interest' => 0,
            'no_profession' => 0,
            'no_gift_type' => 0,
        ];

        foreach ($populationIds as $productId) {
            if (! isset($pivotMaps['primary_category'][$productId])) {
                $counts['no_primary_category']++;
            }

            if (($pivotMaps['category'][$productId] ?? []) === []) {
                $counts['no_category']++;
            }

            if (($pivotMaps['relationship'][$productId] ?? []) === []) {
                $counts['no_relationship']++;
            }

            if (($pivotMaps['occasion'][$productId] ?? []) === []) {
                $counts['no_occasion']++;
            }

            if (($pivotMaps['recipient_type'][$productId] ?? []) === []) {
                $counts['no_recipient_type']++;
            }

            if (($pivotMaps['interest'][$productId] ?? []) === []) {
                $counts['no_interest']++;
            }

            if (($pivotMaps['profession'][$productId] ?? []) === []) {
                $counts['no_profession']++;
            }

            if (($pivotMaps['gift_type'][$productId] ?? []) === []) {
                $counts['no_gift_type']++;
            }
        }

        return new MissingTaxonomySummary(
            noPrimaryCategory: $counts['no_primary_category'],
            noCategory: $counts['no_category'],
            noRelationship: $counts['no_relationship'],
            noOccasion: $counts['no_occasion'],
            noRecipientType: $counts['no_recipient_type'],
            noInterest: $counts['no_interest'],
            noProfession: $counts['no_profession'],
            noGiftType: $counts['no_gift_type'],
        );
    }

    /**
     * @param  Collection<int, int>  $automationDraftIds
     */
    private function summarizeReadiness(Collection $automationDraftIds): AutomationReadinessSummary
    {
        if ($automationDraftIds->isEmpty()) {
            return new AutomationReadinessSummary(
                ready: 0,
                needsReview: 0,
                blocked: 0,
                unevaluated: 0,
            );
        }

        $latestItems = CatalogCandidateSourcingItem::query()
            ->whereIn('product_id', $automationDraftIds)
            ->orderByDesc('id')
            ->get(['id', 'product_id', 'readiness'])
            ->unique('product_id');

        $counts = [
            'ready' => 0,
            'needs_review' => 0,
            'blocked' => 0,
            'unevaluated' => 0,
        ];

        foreach ($automationDraftIds as $productId) {
            $item = $latestItems->firstWhere('product_id', $productId);

            if ($item === null || $item->readiness === null) {
                $counts['unevaluated']++;

                continue;
            }

            match ($item->readiness) {
                ProductAutomationReadiness::Ready => $counts['ready']++,
                ProductAutomationReadiness::NeedsReview => $counts['needs_review']++,
                ProductAutomationReadiness::Blocked => $counts['blocked']++,
            };
        }

        return new AutomationReadinessSummary(
            ready: $counts['ready'],
            needsReview: $counts['needs_review'],
            blocked: $counts['blocked'],
            unevaluated: $counts['unevaluated'],
        );
    }

    /**
     * @param  list<BudgetRangeCoverage>  $budgetCoverage
     * @param  list<DimensionCoverage>  $dimensionCoverage
     * @param  list<CompositeCoverage>  $compositeCoverage
     * @return list<CoverageGap>
     */
    private function deriveGaps(
        array $budgetCoverage,
        array $dimensionCoverage,
        array $compositeCoverage,
        MissingTaxonomySummary $missingTaxonomy,
        int $unpricedCount,
        int $populationTotal,
    ): array {
        $gaps = [];
        $minimumCount = (int) config('catalog_coverage.gaps.minimum_count', 3);
        $compositeMinimum = (int) config('catalog_coverage.gaps.composite_minimum_count', 2);
        $qualityGaps = config('catalog_coverage.quality_gaps', []);
        $optionalGapDimensions = config('catalog_coverage.missing_taxonomy_gap_dimensions', []);

        foreach ($budgetCoverage as $row) {
            if ($row->severity === CoverageGapSeverity::Healthy) {
                continue;
            }

            $gaps[] = new CoverageGap(
                scope: 'budget',
                label: $row->name,
                productCount: $row->productCount,
                severity: $row->severity,
                targetSharePercent: $row->targetSharePercent,
                deltaFromTargetPercent: $row->deltaFromTargetPercent,
            );
        }

        foreach ($dimensionCoverage as $row) {
            $severity = $this->resolveCountSeverity($row->productCount, $minimumCount);

            if ($severity === CoverageGapSeverity::Healthy) {
                continue;
            }

            $gaps[] = new CoverageGap(
                scope: 'dimension',
                label: "{$row->dimension}: {$row->name}",
                productCount: $row->productCount,
                severity: $severity,
            );
        }

        foreach ($compositeCoverage as $row) {
            $severity = $this->resolveCountSeverity($row->productCount, $compositeMinimum);

            if ($severity === CoverageGapSeverity::Healthy) {
                continue;
            }

            $gaps[] = new CoverageGap(
                scope: 'composite',
                label: $row->label,
                productCount: $row->productCount,
                severity: $severity,
            );
        }

        if (($qualityGaps['unpriced'] ?? false) && $unpricedCount > 0) {
            $gaps[] = new CoverageGap(
                scope: 'quality',
                label: 'Unpriced products',
                productCount: $unpricedCount,
                severity: CoverageGapSeverity::Thin,
            );
        }

        $qualityMissingMap = [
            'no_primary_category' => ['enabled' => $qualityGaps['no_primary_category'] ?? false, 'count' => $missingTaxonomy->noPrimaryCategory, 'label' => 'Missing primary category'],
            'no_category' => ['enabled' => $qualityGaps['no_category'] ?? false, 'count' => $missingTaxonomy->noCategory, 'label' => 'Uncategorized products'],
            'no_relationship' => ['enabled' => $qualityGaps['no_relationship'] ?? false, 'count' => $missingTaxonomy->noRelationship, 'label' => 'Missing relationship'],
            'no_occasion' => ['enabled' => $qualityGaps['no_occasion'] ?? false, 'count' => $missingTaxonomy->noOccasion, 'label' => 'Missing occasion'],
        ];

        foreach ($qualityMissingMap as $metric) {
            if (! $metric['enabled'] || $metric['count'] === 0) {
                continue;
            }

            $gaps[] = new CoverageGap(
                scope: 'quality',
                label: $metric['label'],
                productCount: $metric['count'],
                severity: CoverageGapSeverity::Thin,
            );
        }

        $optionalMissingMap = [
            'recipient_type' => $missingTaxonomy->noRecipientType,
            'interest' => $missingTaxonomy->noInterest,
            'profession' => $missingTaxonomy->noProfession,
            'gift_type' => $missingTaxonomy->noGiftType,
        ];

        foreach ($optionalMissingMap as $dimension => $count) {
            if (! in_array($dimension, $optionalGapDimensions, true) || $count === 0) {
                continue;
            }

            $gaps[] = new CoverageGap(
                scope: 'quality',
                label: 'Missing '.$dimension,
                productCount: $count,
                severity: CoverageGapSeverity::Thin,
            );
        }

        return $gaps;
    }

    private function resolveCountSeverity(
        int $count,
        int $minimumCount,
        ?float $percentage = null,
        ?float $targetShare = null,
        float $tolerance = 0.0,
    ): CoverageGapSeverity {
        if ($count === 0) {
            return CoverageGapSeverity::Empty;
        }

        if ($count < $minimumCount) {
            return CoverageGapSeverity::Thin;
        }

        if ($percentage !== null && $targetShare !== null && $percentage < ($targetShare - $tolerance)) {
            return CoverageGapSeverity::Thin;
        }

        return CoverageGapSeverity::Healthy;
    }

    private function isUnpriced(object $product): bool
    {
        return $product->price_amount === null
            || $product->price_currency === null
            || ! is_numeric($product->price_amount);
    }
}
