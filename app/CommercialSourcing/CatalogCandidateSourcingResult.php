<?php

namespace App\CommercialSourcing;

use App\Models\CatalogCandidateSourcingRun;

readonly class CatalogCandidateSourcingResult
{
    /**
     * @param  list<CatalogCandidateSourcingItemOutcome>  $outcomes
     */
    public function __construct(
        public ?CatalogCandidateSourcingRun $run,
        public string $market,
        public int $itemsTotal,
        public int $itemsSucceeded,
        public int $itemsSkipped,
        public int $itemsFailed,
        public array $outcomes,
        public bool $dryRun,
    ) {}
}
