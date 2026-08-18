<?php

namespace App\CatalogCandidate\Ingestion;

use App\Enums\CatalogCandidateIngestionItemStatus;

readonly class CatalogCandidateIngestionItemOutcome
{
    public function __construct(
        public int $index,
        public ?string $title,
        public CatalogCandidateIngestionItemStatus $status,
        public ?string $error,
        public ?int $candidateId = null,
    ) {}
}
