<?php

namespace App\CuratedCatalog;

readonly class CuratedProductIntakePreviewItem
{
    /**
     * @param  list<string>  $warnings
     * @param  list<CuratedSourceListContext>  $sourceLists
     * @param  list<string>  $sourceListNames
     * @param  list<string>  $relationshipHintNames
     * @param  list<string>  $commercialConflicts
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
        public array $sourceLists = [],
        public array $sourceListNames = [],
        public array $relationshipHintNames = [],
        public array $commercialConflicts = [],
        public int $occurrencesMerged = 1,
        public ?MergedCuratedMerchantProduct $merged = null,
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

    public function sourceListCount(): int
    {
        return count($this->sourceLists);
    }
}
