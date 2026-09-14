<?php

namespace App\RecipientMerchandising;

readonly class RecipientMerchandisingBackfillResult
{
    /**
     * @param  array{
     *     total_rows: int,
     *     excluded_soft_deleted: int,
     *     excluded_other: int,
     *     eligible: int,
     *     eligible_published: int,
     *     eligible_draft: int,
     *     eligible_archived: int,
     * }  $inventory
     * @param  array<string, int>  $genderCounts
     * @param  array<string, int>  $recipientTypeCounts
     * @param  list<RecipientMerchandisingBackfillAssignment>  $assignments
     * @param  array<string, list<RecipientMerchandisingBackfillAssignment>>  $sampleGroups
     */
    public function __construct(
        public array $inventory,
        public int $examined,
        public int $wouldApply,
        public int $applied,
        public int $skippedHumanLocked,
        public int $skippedAlreadyClassified,
        public int $ambiguous,
        public int $noEvidence,
        public bool $dryRun,
        public array $genderCounts,
        public array $recipientTypeCounts,
        public array $assignments,
        public array $sampleGroups,
    ) {}
}
