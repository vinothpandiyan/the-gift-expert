<?php

namespace App\RecipientMerchandising;

readonly class RecipientMerchandisingBackfillAssignment
{
    /**
     * @param  list<string>  $recipientTypeSlugs
     * @param  list<string>  $reasons
     */
    public function __construct(
        public int $productId,
        public string $productName,
        public ?string $recipientGenderSlug,
        public array $recipientTypeSlugs,
        public string $decision,
        public array $reasons,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'recipient_gender' => $this->recipientGenderSlug,
            'recipient_types' => $this->recipientTypeSlugs,
            'decision' => $this->decision,
            'reasons' => $this->reasons,
        ];
    }
}
