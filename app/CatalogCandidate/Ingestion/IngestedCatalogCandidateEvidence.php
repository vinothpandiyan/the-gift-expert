<?php

namespace App\CatalogCandidate\Ingestion;

use App\Enums\CatalogCandidateSourceType;

readonly class IngestedCatalogCandidateEvidence
{
    /**
     * @param  array<string, scalar|null>|null  $metadata
     */
    public function __construct(
        public CatalogCandidateSourceType $sourceType,
        public ?string $sourceName,
        public ?string $sourceUrl,
        public ?string $summary,
        public mixed $observedAt,
        public ?array $metadata,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source_name' => $this->sourceName,
            'source_url' => $this->sourceUrl,
            'summary' => $this->summary,
            'observed_at' => $this->observedAt,
            'metadata' => $this->metadata,
        ];
    }
}
