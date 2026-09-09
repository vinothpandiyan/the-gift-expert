<?php

namespace App\ProductImage;

readonly class SafeOuterBackgroundTrimPlan
{
    /**
     * @param  array{x: int, y: int, width: int, height: int}|null  $contentBox
     * @param  array{x: int, y: int, width: int, height: int}|null  $cropBox
     */
    public function __construct(
        public bool $shouldTrim,
        public string $reason,
        public int $sourceWidth,
        public int $sourceHeight,
        public ?array $contentBox = null,
        public ?array $cropBox = null,
        public ?float $occupancyBefore = null,
        public ?float $occupancyAfter = null,
    ) {}
}
