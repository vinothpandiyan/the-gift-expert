<?php

namespace App\GapSourcing;

use App\Enums\GapSourcingOverlap;

readonly class CatalogConceptMatch
{
    /**
     * @param  list<string>  $giftIntents
     */
    public function __construct(
        public GapSourcingOverlap $overlap,
        public ?int $productId,
        public ?string $title,
        public ?string $concept,
        public ?string $status,
        public ?string $decision,
        public ?float $priceAmount,
        public array $giftIntents,
        public ?string $summary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'overlap' => $this->overlap->value,
            'product_id' => $this->productId,
            'title' => $this->title,
            'concept' => $this->concept,
            'status' => $this->status,
            'decision' => $this->decision,
            'price_amount' => $this->priceAmount,
            'gift_intents' => $this->giftIntents,
            'summary' => $this->summary,
        ];
    }
}
