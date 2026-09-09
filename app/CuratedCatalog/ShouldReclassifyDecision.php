<?php

namespace App\CuratedCatalog;

readonly class ShouldReclassifyDecision
{
    public function __construct(
        public bool $shouldReclassify,
        public string $reason,
    ) {}
}
