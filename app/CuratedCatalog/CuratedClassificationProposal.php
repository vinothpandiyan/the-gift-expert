<?php

namespace App\CuratedCatalog;

use App\CommercialSourcing\ValidatedProductTaxonomyClassification;
use App\Enums\TaxonomyClassificationWarningCode;

readonly class CuratedClassificationProposal
{
    /**
     * @param  list<int>  $sourceRelationshipHintIds
     * @param  list<string>  $warnings
     * @param  list<string>  $reviewReasons
     * @param  array<string, string>  $reasoningSummary
     */
    public function __construct(
        public ValidatedProductTaxonomyClassification $taxonomy,
        public CuratedClassificationConfidence $confidence,
        public array $reasoningSummary,
        public CuratedTaxonomyGap $taxonomyGap,
        public array $sourceRelationshipHintIds,
        public array $warnings,
        public array $reviewReasons,
        public int $classificationVersion,
        public ?string $name = null,
        public ?string $shortDescription = null,
        public ?string $description = null,
        public ?string $brand = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'primary_category_id' => $this->taxonomy->primaryCategoryId,
            'category_ids' => $this->taxonomy->categoryIds,
            'relationship_ids' => $this->taxonomy->relationshipIds,
            'recipient_type_ids' => $this->taxonomy->recipientTypeIds,
            'occasion_ids' => $this->taxonomy->occasionIds,
            'interest_ids' => $this->taxonomy->interestIds,
            'profession_ids' => $this->taxonomy->professionIds,
            'gift_type_ids' => $this->taxonomy->giftTypeIds,
            'exception_codes' => $this->taxonomy->exceptionCodes,
            'rejected_ids' => $this->taxonomy->rejectedIds,
            'confidence' => $this->confidence->toArray(),
            'reasoning_summary' => $this->reasoningSummary,
            'taxonomy_gap' => $this->taxonomyGap->toArray(),
            'source_relationship_hint_ids' => $this->sourceRelationshipHintIds,
            'warnings' => $this->warnings,
            'review_reasons' => $this->reviewReasons,
            'classification_version' => $this->classificationVersion,
            'name' => $this->name,
            'short_description' => $this->shortDescription,
            'description' => $this->description,
            'brand' => $this->brand,
        ];
    }

    public function withWarnings(array $warnings, array $reviewReasons): self
    {
        return new self(
            taxonomy: $this->taxonomy,
            confidence: $this->confidence,
            reasoningSummary: $this->reasoningSummary,
            taxonomyGap: $this->taxonomyGap,
            sourceRelationshipHintIds: $this->sourceRelationshipHintIds,
            warnings: array_values(array_unique($warnings)),
            reviewReasons: array_values(array_unique($reviewReasons)),
            classificationVersion: $this->classificationVersion,
            name: $this->name,
            shortDescription: $this->shortDescription,
            description: $this->description,
            brand: $this->brand,
        );
    }

    public function withTaxonomy(ValidatedProductTaxonomyClassification $taxonomy): self
    {
        return new self(
            taxonomy: $taxonomy,
            confidence: $this->confidence,
            reasoningSummary: $this->reasoningSummary,
            taxonomyGap: $this->taxonomyGap,
            sourceRelationshipHintIds: $this->sourceRelationshipHintIds,
            warnings: $this->warnings,
            reviewReasons: $this->reviewReasons,
            classificationVersion: $this->classificationVersion,
            name: $this->name,
            shortDescription: $this->shortDescription,
            description: $this->description,
            brand: $this->brand,
        );
    }

    public static function warning(TaxonomyClassificationWarningCode $code): string
    {
        return $code->value;
    }
}
