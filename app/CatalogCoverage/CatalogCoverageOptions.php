<?php

namespace App\CatalogCoverage;

readonly class CatalogCoverageOptions
{
    public function __construct(
        public bool $publishedOnly = false,
        public bool $includeManualDrafts = false,
        public ?string $dimensionFilter = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function from(array $attributes): self
    {
        $dimension = $attributes['dimension'] ?? $attributes['dimension_filter'] ?? null;

        if ($dimension !== null && $dimension !== '') {
            $dimension = strtolower(trim((string) $dimension));
        } else {
            $dimension = null;
        }

        return new self(
            publishedOnly: (bool) ($attributes['published_only'] ?? false),
            includeManualDrafts: (bool) ($attributes['include_manual_drafts'] ?? false),
            dimensionFilter: $dimension,
        );
    }
}
