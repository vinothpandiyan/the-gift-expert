<?php

namespace App\CommercialSourcing;

readonly class CatalogCandidatePromotionResult
{
    /**
     * @param  list<string>  $exceptionCodes
     */
    public function __construct(
        public bool $promoted,
        public ?int $productId,
        public ?int $affiliateLinkId,
        public array $exceptionCodes,
        public ?string $error,
        public ?string $imageNote,
        public bool $dryRun = false,
    ) {}
}
