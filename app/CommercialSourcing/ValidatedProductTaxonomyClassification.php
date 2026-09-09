<?php

namespace App\CommercialSourcing;

readonly class ValidatedProductTaxonomyClassification
{
    /**
     * @param  list<int>  $categoryIds
     * @param  list<int>  $occasionIds
     * @param  list<int>  $relationshipIds
     * @param  list<int>  $recipientTypeIds
     * @param  list<int>  $interestIds
     * @param  list<int>  $professionIds
     * @param  list<int>  $giftTypeIds
     * @param  list<string>  $exceptionCodes
     * @param  list<int>  $rejectedIds
     */
    public function __construct(
        public ?int $primaryCategoryId,
        public array $categoryIds,
        public array $occasionIds,
        public array $relationshipIds,
        public array $recipientTypeIds,
        public array $interestIds,
        public array $professionIds,
        public array $giftTypeIds,
        public array $exceptionCodes,
        public array $rejectedIds,
    ) {}

    /**
     * @param  list<int>|null  $categoryIds
     * @param  list<int>|null  $occasionIds
     * @param  list<int>|null  $relationshipIds
     * @param  list<int>|null  $recipientTypeIds
     * @param  list<int>|null  $interestIds
     * @param  list<int>|null  $professionIds
     * @param  list<int>|null  $giftTypeIds
     * @param  list<string>|null  $exceptionCodes
     * @param  list<int>|null  $rejectedIds
     */
    public function with(
        ?int $primaryCategoryId = null,
        ?array $categoryIds = null,
        ?array $occasionIds = null,
        ?array $relationshipIds = null,
        ?array $recipientTypeIds = null,
        ?array $interestIds = null,
        ?array $professionIds = null,
        ?array $giftTypeIds = null,
        ?array $exceptionCodes = null,
        ?array $rejectedIds = null,
        bool $clearPrimaryCategory = false,
    ): self {
        return new self(
            primaryCategoryId: $clearPrimaryCategory ? null : ($primaryCategoryId ?? $this->primaryCategoryId),
            categoryIds: $categoryIds ?? $this->categoryIds,
            occasionIds: $occasionIds ?? $this->occasionIds,
            relationshipIds: $relationshipIds ?? $this->relationshipIds,
            recipientTypeIds: $recipientTypeIds ?? $this->recipientTypeIds,
            interestIds: $interestIds ?? $this->interestIds,
            professionIds: $professionIds ?? $this->professionIds,
            giftTypeIds: $giftTypeIds ?? $this->giftTypeIds,
            exceptionCodes: $exceptionCodes ?? $this->exceptionCodes,
            rejectedIds: $rejectedIds ?? $this->rejectedIds,
        );
    }
}
