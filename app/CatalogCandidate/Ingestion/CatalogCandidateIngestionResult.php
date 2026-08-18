<?php

namespace App\CatalogCandidate\Ingestion;

use App\Models\CatalogCandidateIngestionRun;

readonly class CatalogCandidateIngestionResult
{
    /**
     * @param  list<CatalogCandidateIngestionItemOutcome>  $outcomes
     */
    public function __construct(
        public int $itemsTotal,
        public int $itemsSucceeded,
        public int $itemsSkipped,
        public int $itemsFailed,
        public array $outcomes,
        public ?CatalogCandidateIngestionRun $run = null,
    ) {}
}
