<?php

namespace App\CuratedCatalog;

readonly class CuratedProductIntakeItemResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public bool $success,
        public string $outcome,
        public ?int $productId,
        public ?int $affiliateLinkId,
        public array $warnings,
        public ?string $error,
    ) {}
}
