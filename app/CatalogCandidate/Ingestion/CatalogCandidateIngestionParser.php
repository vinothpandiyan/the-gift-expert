<?php

namespace App\CatalogCandidate\Ingestion;

interface CatalogCandidateIngestionParser
{
    /**
     * @return iterable<IngestedCatalogCandidate|IngestionRowError>
     */
    public function parse(string $contents): iterable;
}
