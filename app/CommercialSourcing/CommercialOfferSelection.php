<?php

namespace App\CommercialSourcing;

use App\Models\CatalogCandidate;

readonly class CommercialOfferSelection
{
    /**
     * @param  list<SourcedMerchantOffer>  $ordered
     * @param  array<string, int>  $rankBreakdown
     */
    public function __construct(
        public CatalogCandidate $candidate,
        public ?SourcedMerchantOffer $selected,
        public array $ordered,
        public array $rankBreakdown = [],
    ) {}
}
