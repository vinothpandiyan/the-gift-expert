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
}
