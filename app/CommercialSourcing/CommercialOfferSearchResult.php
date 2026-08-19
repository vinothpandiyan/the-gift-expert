<?php

namespace App\CommercialSourcing;

readonly class CommercialOfferSearchResult
{
    /**
     * @param  list<CommercialSearchHit>  $hits
     * @param  list<string>  $queries
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $hits,
        public array $queries,
        public array $metadata = [],
    ) {}
}
