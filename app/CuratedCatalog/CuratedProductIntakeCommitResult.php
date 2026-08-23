<?php

namespace App\CuratedCatalog;

readonly class CuratedProductIntakeCommitResult
{
    /**
     * @param  list<array<string, mixed>>  $processedItems
     */
    public function __construct(
        public int $runId,
        public int $itemsProcessed,
        public int $itemsCreated,
        public int $itemsUpdated,
        public int $itemsSkipped,
        public int $itemsFailed,
        public int $itemsRemaining,
        public array $processedItems,
    ) {}
}
