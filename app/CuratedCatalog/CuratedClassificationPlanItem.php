<?php

namespace App\CuratedCatalog;

readonly class CuratedClassificationPlanItem
{
    /**
     * @param  list<string>  $hintSlugs
     */
    public function __construct(
        public int $productId,
        public string $status,
        public ?int $version,
        public string $decisionReason,
        public bool $eligible,
        public array $hintSlugs,
        public ?string $externalProductId,
        public ?string $merchantSlug,
        public string $sourceTitle,
    ) {}
}
