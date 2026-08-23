<?php

namespace App\CuratedCatalog;

readonly class CuratedProductIntakeStartedResult
{
    public function __construct(
        public int $runId,
        public int $itemsTotal,
        public int $itemsActionable,
    ) {}

    public function isProcessing(): bool
    {
        return true;
    }
}
