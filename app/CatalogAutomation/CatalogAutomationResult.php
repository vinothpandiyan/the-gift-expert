<?php

namespace App\CatalogAutomation;

use App\Enums\CatalogAutomationStage;

readonly class CatalogAutomationResult
{
    /**
     * @param  list<array{candidateId: int|null, code: string, message: string}>  $failures
     * @param  list<int>  $productIds
     */
    public function __construct(
        public ?int $automationRunId,
        public ?int $discoveryRunId,
        public ?int $sourcingRunId,
        public int $queriesCount,
        public int $candidatesProposed,
        public int $candidatesAdded,
        public int $candidatesDuplicate,
        public int $existingCandidatesContinued,
        public int $alreadyPromotedSkipped,
        public int $candidatesSourced,
        public int $candidatesEnriched,
        public int $candidatesPromoted,
        public int $ready,
        public int $needsReview,
        public int $blocked,
        public array $failures,
        public array $productIds,
        public bool $dryRun,
        public CatalogAutomationStage $stoppedAfter,
        public bool $downstreamSkipped = false,
        public ?string $downstreamSkippedReason = null,
    ) {}
}
