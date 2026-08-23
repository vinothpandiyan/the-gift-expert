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
    ) {}
}
