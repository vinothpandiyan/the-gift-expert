<?php

namespace App\CatalogCandidate\Ingestion;

readonly class IngestionRowError
{
    /**
     * @param  array<string, mixed>|null  $sourcePayload
     */
    public function __construct(
        public int $index,
        public string $message,
        public ?array $sourcePayload = null,
        public bool $skip = false,
        public ?string $title = null,
    ) {}
}
