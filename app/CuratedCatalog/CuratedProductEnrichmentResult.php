<?php

namespace App\CuratedCatalog;

use App\CommercialSourcing\ValidatedProductTaxonomyClassification;

readonly class CuratedProductEnrichmentResult
{
    /**
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $reasoningSummary
     */
    public function __construct(
        public string $name,
        public ?string $shortDescription,
        public ?string $description,
        public ?string $brand,
        public ValidatedProductTaxonomyClassification $taxonomy,
        public array $warnings,
        public array $metadata,
        public CuratedClassificationConfidence $confidence = new CuratedClassificationConfidence,
        public array $reasoningSummary = [],
        public CuratedTaxonomyGap $taxonomyGap = new CuratedTaxonomyGap,
    ) {}

    public function toTaxonomyClassification(): ValidatedProductTaxonomyClassification
    {
        return $this->taxonomy;
    }
}
