<?php

namespace App\CatalogCandidate\Ingestion;

use App\Enums\CatalogCandidatePriority;
use App\Enums\CatalogCandidateSourceType;

readonly class IngestedCatalogCandidate
{
    /**
     * @param  list<IngestedCatalogCandidateEvidence>  $evidence
     * @param  array<string, mixed>  $sourcePayload
     */
    public function __construct(
        public int $index,
        public string $title,
        public CatalogCandidateSourceType $sourceType,
        public ?string $summary,
        public ?string $notes,
        public ?CatalogCandidatePriority $priority,
        public ?string $sourceName,
        public ?string $sourceUrl,
        public ?string $externalReference,
        public mixed $estimatedPriceAmount,
        public ?string $estimatedPriceCurrency,
        public mixed $discoveredAt,
        public bool $allowSimilarTitle,
        public array $evidence,
        public array $sourcePayload,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toCandidateAttributes(?int $createdByUserId = null): array
    {
        $attributes = [
            'title' => $this->title,
            'summary' => $this->summary,
            'notes' => $this->notes,
            'source_type' => $this->sourceType,
            'source_name' => $this->sourceName,
            'source_url' => $this->sourceUrl,
            'external_reference' => $this->externalReference,
            'estimated_price_amount' => $this->estimatedPriceAmount,
            'estimated_price_currency' => $this->estimatedPriceCurrency,
            'created_by_user_id' => $createdByUserId,
        ];

        if ($this->priority !== null) {
            $attributes['priority'] = $this->priority;
        }

        if ($this->discoveredAt !== null) {
            $attributes['discovered_at'] = $this->discoveredAt;
        }

        return $attributes;
    }
}
