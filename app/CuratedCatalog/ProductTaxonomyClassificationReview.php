<?php

namespace App\CuratedCatalog;

use App\Enums\TaxonomyClassificationStatus;

readonly class ProductTaxonomyClassificationReview
{
    /**
     * @param  list<array{code: string, label: string, description: string}>  $reviewReasons
     * @param  list<array{code: string, label: string, description: string}>  $warnings
     * @param  list<array{label: string, names: list<string>, items: list<array{name: string}>, confidence: ?string, below_threshold: bool}>  $proposalDimensions
     * @param  list<array{label: string, names: list<string>, items: list<array{name: string}>}>  $appliedDimensions
     * @param  list<array{name: string, kind: string, relationship: ?string, first_seen: ?string, last_seen: ?string, is_trusted_hint: bool}>  $provenance
     * @param  list<string>  $trustedHintNames
     * @param  list<string>  $proposedRelationshipNames
     * @param  list<array{label: string, text: string}>  $reasoningBlocks
     */
    public function __construct(
        public string $title,
        public ?string $imageUrl,
        public ?string $merchantName,
        public ?string $externalProductId,
        public ?string $priceDisplay,
        public string $availabilityLabel,
        public string $productStatus,
        public TaxonomyClassificationStatus $classificationStatus,
        public ?int $classificationVersion,
        public ?string $classifiedAt,
        public ?string $approvedAt,
        public ?string $approvedBy,
        public bool $proposalPending,
        public bool $proposalStale,
        public array $reviewReasons,
        public array $warnings,
        public ?string $gapSuggestion,
        public ?string $gapExplanation,
        public ?string $reasoning,
        public array $reasoningBlocks,
        public array $proposalDimensions,
        public array $appliedDimensions,
        public array $provenance,
        public array $trustedHintNames,
        public array $proposedRelationshipNames,
    ) {}
}
