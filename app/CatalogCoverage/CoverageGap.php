<?php

namespace App\CatalogCoverage;

use App\Enums\CoverageGapSeverity;

readonly class CoverageGap
{
    public function __construct(
        public string $scope,
        public string $label,
        public int $productCount,
        public CoverageGapSeverity $severity,
        public ?float $targetSharePercent = null,
        public ?float $deltaFromTargetPercent = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'label' => $this->label,
            'product_count' => $this->productCount,
            'severity' => $this->severity->value,
            'target_share_percent' => $this->targetSharePercent,
            'delta_from_target_percent' => $this->deltaFromTargetPercent,
        ];
    }
}
