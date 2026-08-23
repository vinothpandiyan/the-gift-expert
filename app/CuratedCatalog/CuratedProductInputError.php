<?php

namespace App\CuratedCatalog;

readonly class CuratedProductInputError
{
    public function __construct(
        public int $itemIndex,
        public string $code,
        public string $message,
    ) {}
}
