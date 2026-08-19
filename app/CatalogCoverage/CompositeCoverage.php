<?php

namespace App\CatalogCoverage;

readonly class CompositeCoverage
{
    /**
     * @param  array<string, string>  $dimensionValues
     */
    public function __construct(
        public string $compositeKey,
        public string $label,
        public array $dimensionValues,
        public int $productCount,
        public int $publishedCount,
        public int $automationDraftCount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'composite_key' => $this->compositeKey,
            'label' => $this->label,
            'dimension_values' => $this->dimensionValues,
            'product_count' => $this->productCount,
            'published_count' => $this->publishedCount,
            'automation_draft_count' => $this->automationDraftCount,
        ];
    }
}
