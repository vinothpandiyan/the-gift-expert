<?php

namespace App\CommercialSourcing;

readonly class CommercialTaxonomyCatalog
{
    /**
     * @param  list<array<string, mixed>>  $categories
     * @param  list<array<string, mixed>>  $occasions
     * @param  list<array<string, mixed>>  $relationships
     * @param  list<array<string, mixed>>  $recipientTypes
     * @param  list<array<string, mixed>>  $interests
     * @param  list<array<string, mixed>>  $professions
     * @param  list<array<string, mixed>>  $giftTypes
     */
    public function __construct(
        public array $categories,
        public array $occasions,
        public array $relationships,
        public array $recipientTypes,
        public array $interests,
        public array $professions,
        public array $giftTypes,
    ) {}

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function toPromptArray(): array
    {
        return [
            'categories' => $this->categories,
            'occasions' => $this->occasions,
            'relationships' => $this->relationships,
            'recipient_types' => $this->recipientTypes,
            'interests' => $this->interests,
            'professions' => $this->professions,
            'gift_types' => $this->giftTypes,
        ];
    }
}
