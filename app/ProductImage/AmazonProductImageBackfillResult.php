<?php

namespace App\ProductImage;

readonly class AmazonProductImageBackfillResult
{
    /**
     * @param  list<array{product_id: int, image_id: int, reason: string}>  $failures
     */
    public function __construct(
        public int $examined,
        public int $replaced,
        public int $skippedOperatorManaged,
        public int $skippedNotAmazon,
        public int $skippedAlreadyHighResolution,
        public int $failed,
        public bool $dryRun,
        public array $failures = [],
    ) {}
}
