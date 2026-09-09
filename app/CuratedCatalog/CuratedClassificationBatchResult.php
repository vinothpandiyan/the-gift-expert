<?php

namespace App\CuratedCatalog;

readonly class CuratedClassificationBatchResult
{
    /**
     * @param  list<int>  $acceptedProductIds
     * @param  list<int>  $reviewProductIds
     * @param  list<int>  $failedProductIds
     */
    public function __construct(
        public int $totalEligible,
        public int $processed,
        public int $aiAccepted,
        public int $review,
        public int $failed,
        public int $skippedCurrent,
        public int $skippedLocked,
        public int $remaining,
        public int $aiCalls,
        public array $acceptedProductIds,
        public array $reviewProductIds,
        public array $failedProductIds,
        public int $queued = 0,
    ) {}
}
