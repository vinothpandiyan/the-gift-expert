<?php

namespace App\CatalogCuration;

readonly class ConceptCurationProgress
{
    public function __construct(
        public string $key,
        public string $label,
        public int $products,
        public int $reviewed,
        public int $remaining,
    ) {}
}
