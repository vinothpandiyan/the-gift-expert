<?php

namespace App\CatalogCandidate\Discovery;

readonly class RetrievedCatalogCandidateSource
{
    public function __construct(
        public string $url,
        public string $title,
        public string $snippet,
        public string $sourceName,
        public mixed $retrievedAt,
    ) {}
}
