<?php

namespace App\CatalogCandidate\Discovery;

readonly class CatalogCandidateDiscoveryResult
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<RetrievedCatalogCandidateSource>  $corpus
     * @param  list<string>  $queries
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $candidates,
        public array $corpus,
        public array $queries = [],
        public array $metadata = [],
    ) {}
}
