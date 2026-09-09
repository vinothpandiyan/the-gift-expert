<?php

namespace App\CuratedCatalog;

readonly class CuratedMerchantProductInput
{
    /**
     * @param  array<string, mixed>  $sourcePayload
     */
    public function __construct(
        public int $itemIndex,
        public string $merchantSlug,
        public string $externalProductId,
        public string $sourceUrl,
        public string $title,
        public ?string $priceAmount,
        public ?string $priceCurrency,
        public ?string $sourceImageUrl,
        public ?string $availability,
        public ?string $capturedAt,
        public ?string $curationGroup,
        public array $sourcePayload,
        public ?CuratedSourceListContext $sourceListContext = null,
    ) {}

    public function identityKey(): string
    {
        return $this->merchantSlug.'|'.$this->externalProductId;
    }

    public function withMergedCommercialFields(
        string $sourceUrl,
        string $title,
        ?string $priceAmount,
        ?string $priceCurrency,
        ?string $sourceImageUrl,
        ?string $availability,
        ?string $capturedAt,
    ): self {
        $payload = $this->sourcePayload;
        $payload['source_url'] = $sourceUrl;
        $payload['title'] = $title;
        $payload['price_amount'] = $priceAmount;
        $payload['price_currency'] = $priceCurrency;
        $payload['source_image_url'] = $sourceImageUrl;
        $payload['availability'] = $availability;
        $payload['captured_at'] = $capturedAt;

        return new self(
            itemIndex: $this->itemIndex,
            merchantSlug: $this->merchantSlug,
            externalProductId: $this->externalProductId,
            sourceUrl: $sourceUrl,
            title: $title,
            priceAmount: $priceAmount,
            priceCurrency: $priceCurrency,
            sourceImageUrl: $sourceImageUrl,
            availability: $availability,
            capturedAt: $capturedAt,
            curationGroup: $this->curationGroup,
            sourcePayload: $payload,
            sourceListContext: $this->sourceListContext,
        );
    }
}
