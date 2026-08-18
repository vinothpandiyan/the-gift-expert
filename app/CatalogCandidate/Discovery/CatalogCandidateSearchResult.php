<?php

namespace App\CatalogCandidate\Discovery;

readonly class CatalogCandidateSearchResult
{
    /**
     * @param  list<RetrievedCatalogCandidateSource>  $corpus
     * @param  list<string>  $queries
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $corpus,
        public array $queries,
        public array $metadata = [],
    ) {}
}
