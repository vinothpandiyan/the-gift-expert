<?php

namespace App\CommercialSourcing;

readonly class CommercialSearchHit
{
    /**
     * @param  list<string>  $imageUrls
     */
    public function __construct(
        public string $url,
        public string $title,
        public string $snippet,
        public array $imageUrls,
        public mixed $retrievedAt,
    ) {}
}
