<?php

namespace App\CuratedCatalog;

readonly class CatalogSourceListMapping
{
    public function __construct(
        public string $kind,
        public ?string $relationshipSlug,
        public bool $isMapped,
        public string $matchedBy,
    ) {}
}
