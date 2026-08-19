<?php

namespace App\CatalogCoverage;

use App\Enums\CoverageGapSeverity;

readonly class BudgetRangeCoverage
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public int $productCount,
        public int $publishedCount,
        public int $automationDraftCount,
        public float $percentageOfTotal,
        public ?float $targetSharePercent,
        public ?float $deltaFromTargetPercent,
        public CoverageGapSeverity $severity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'product_count' => $this->productCount,
            'published_count' => $this->publishedCount,
            'automation_draft_count' => $this->automationDraftCount,
            'percentage_of_total' => $this->percentageOfTotal,
            'target_share_percent' => $this->targetSharePercent,
            'delta_from_target_percent' => $this->deltaFromTargetPercent,
            'severity' => $this->severity->value,
        ];
    }
}
