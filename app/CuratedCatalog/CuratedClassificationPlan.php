<?php

namespace App\CuratedCatalog;

readonly class CuratedClassificationPlan
{
    /**
     * @param  list<CuratedClassificationPlanItem>  $items
     */
    public function __construct(
        public int $totalConsidered,
        public int $eligible,
        public int $skippedCurrent,
        public int $skippedLocked,
        public int $skippedOther,
        public int $withTrustedHints,
        public int $withoutRecipientHints,
        public int $failedWouldRetry,
        public int $failedWouldSkip,
        public int $classificationVersion,
        public int $estimatedMaxOutputTokens,
        public array $items,
        public ?int $intakeRunId,
    ) {}
}
