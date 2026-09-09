<?php

namespace App\Actions\ProductImage;

readonly class NormalizedAmazonProductImageUrl
{
    public function __construct(
        public string $url,
        public bool $isAmazon,
        public bool $changed,
        public ?int $detectedLongEdge,
        public ?int $requestedLongEdge,
    ) {}
}
