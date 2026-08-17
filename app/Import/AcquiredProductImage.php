<?php

namespace App\Import;

readonly class AcquiredProductImage
{
    public function __construct(
        public string $path,
        public string $contentHash,
    ) {}
}
