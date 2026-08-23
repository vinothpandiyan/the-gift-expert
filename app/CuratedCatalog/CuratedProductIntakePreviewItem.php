<?php

namespace App\CuratedCatalog;

readonly class CuratedProductIntakePreviewItem
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $itemIndex,
        public ?CuratedMerchantProductInput $input,
        public ?CuratedProductInputError $error,
        public string $disposition,
        public string $proposedAction,
        public array $warnings,
        public bool $affiliateReady,
        public ?string $affiliateReasonCode,
        public ?int $productId,
        public ?int $affiliateLinkId,
    ) {}

    public function externalProductId(): ?string
    {
        return $this->input?->externalProductId;
    }

    public function title(): ?string
    {
        return $this->input?->title ?? $this->error?->message;
    }

    public function priceAmount(): ?string
    {
        return $this->input?->priceAmount;
    }

    public function sourceImageUrl(): ?string
    {
        return $this->input?->sourceImageUrl;
    }

    public function availability(): ?string
    {
        return $this->input?->availability;
    }
}
