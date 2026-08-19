<?php

namespace App\CatalogCoverage;

readonly class MissingTaxonomySummary
{
    public function __construct(
        public int $noPrimaryCategory,
        public int $noCategory,
        public int $noRelationship,
        public int $noOccasion,
        public int $noRecipientType,
        public int $noInterest,
        public int $noProfession,
        public int $noGiftType,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'no_primary_category' => $this->noPrimaryCategory,
            'no_category' => $this->noCategory,
            'no_relationship' => $this->noRelationship,
            'no_occasion' => $this->noOccasion,
            'no_recipient_type' => $this->noRecipientType,
            'no_interest' => $this->noInterest,
            'no_profession' => $this->noProfession,
            'no_gift_type' => $this->noGiftType,
        ];
    }
}
