<?php

namespace App\LaunchPublication;

readonly class LaunchPublicationReadinessRow
{
    /**
     * @param  list<string>  $blockingCodes
     * @param  list<string>  $blockingReasons
     * @param  list<string>  $warningCodes
     * @param  list<string>  $warningMessages
     * @param  list<string>  $giftIntents
     */
    public function __construct(
        public int $productId,
        public string $title,
        public ?string $humanDecision,
        public ?string $humanMerchandisingRole,
        public ?int $giftScore,
        public ?int $catalogValue,
        public ?string $priceAmount,
        public ?string $availability,
        public bool $ready,
        public array $blockingCodes,
        public array $blockingReasons,
        public array $warningCodes,
        public array $warningMessages,
        public ?string $conceptKey,
        public ?string $conceptLabel,
        public array $giftIntents,
        public ?string $budgetBand,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'title' => $this->title,
            'human_decision' => $this->humanDecision,
            'human_merchandising_role' => $this->humanMerchandisingRole,
            'gift_score' => $this->giftScore,
            'catalog_value' => $this->catalogValue,
            'price_amount' => $this->priceAmount,
            'availability' => $this->availability,
            'readiness' => $this->ready ? 'READY' : 'BLOCKED',
            'blocking_codes' => $this->blockingCodes,
            'blocking_reasons' => $this->blockingReasons,
            'warning_codes' => $this->warningCodes,
            'warning_messages' => $this->warningMessages,
            'concept_key' => $this->conceptKey,
            'concept_label' => $this->conceptLabel,
            'gift_intents' => $this->giftIntents,
            'budget_band' => $this->budgetBand,
        ];
    }
}
