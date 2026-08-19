<?php

namespace App\CatalogCoverage;

use App\Enums\CoverageGapSeverity;
use Illuminate\Support\Carbon;

readonly class CatalogCoverageReport
{
    /**
     * @param  list<BudgetRangeCoverage>  $budgetCoverage
     * @param  list<DimensionCoverage>  $dimensionCoverage
     * @param  list<CompositeCoverage>  $compositeCoverage
     * @param  list<CoverageGap>  $gaps
     */
    public function __construct(
        public Carbon $generatedAt,
        public int $totalProducts,
        public int $publishedProducts,
        public int $automationDraftProducts,
        public int $unpricedCount,
        public MissingTaxonomySummary $missingTaxonomy,
        public AutomationReadinessSummary $readiness,
        public array $budgetCoverage,
        public array $dimensionCoverage,
        public array $compositeCoverage,
        public array $gaps,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt->toIso8601String(),
            'total_products' => $this->totalProducts,
            'published_products' => $this->publishedProducts,
            'automation_draft_products' => $this->automationDraftProducts,
            'unpriced_count' => $this->unpricedCount,
            'missing_taxonomy' => $this->missingTaxonomy->toArray(),
            'readiness' => $this->readiness->toArray(),
            'budget_coverage' => array_map(fn (BudgetRangeCoverage $row) => $row->toArray(), $this->budgetCoverage),
            'dimension_coverage' => array_map(fn (DimensionCoverage $row) => $row->toArray(), $this->dimensionCoverage),
            'composite_coverage' => array_map(fn (CompositeCoverage $row) => $row->toArray(), $this->compositeCoverage),
            'gaps' => array_map(fn (CoverageGap $gap) => $gap->toArray(), $this->gaps),
        ];
    }

    /**
     * @return list<CoverageGap>
     */
    public function topGaps(?int $limit = null): array
    {
        $limit ??= (int) config('catalog_coverage.max_reported_gaps', 20);

        $sorted = $this->gaps;

        usort($sorted, function (CoverageGap $a, CoverageGap $b): int {
            $severityOrder = [
                CoverageGapSeverity::Empty->value => 0,
                CoverageGapSeverity::Thin->value => 1,
                CoverageGapSeverity::Healthy->value => 2,
            ];

            $severityCompare = ($severityOrder[$a->severity->value] ?? 99)
                <=> ($severityOrder[$b->severity->value] ?? 99);

            if ($severityCompare !== 0) {
                return $severityCompare;
            }

            if ($a->productCount !== $b->productCount) {
                return $a->productCount <=> $b->productCount;
            }

            return strcmp($a->label, $b->label);
        });

        return array_slice($sorted, 0, max(0, $limit));
    }
}
