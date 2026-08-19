<?php

namespace App\CatalogCoverage;

readonly class DimensionCoverage
{
    public function __construct(
        public string $dimension,
        public int $id,
        public string $slug,
        public string $name,
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
            'dimension' => $this->dimension,
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'product_count' => $this->productCount,
            'published_count' => $this->publishedCount,
            'automation_draft_count' => $this->automationDraftCount,
        ];
    }
}
