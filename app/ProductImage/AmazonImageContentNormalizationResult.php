<?php

namespace App\ProductImage;

readonly class AmazonImageContentNormalizationResult
{
    /**
     * @param  list<array{product_id: int, image_id: int, reason: string}>  $skips
     * @param  list<array{product_id: int, image_id: int, reason: string}>  $failures
     */
    public function __construct(
        public int $examined,
        public int $normalized,
        public int $skippedOperatorManaged,
        public int $skippedNotAmazon,
        public int $skippedUnsafe,
        public int $skippedAlreadyNormalized,
        public int $failed,
        public bool $dryRun,
        public float $averageOccupancyBefore,
        public float $averageOccupancyAfter,
        public array $skips = [],
        public array $failures = [],
    ) {}
}
