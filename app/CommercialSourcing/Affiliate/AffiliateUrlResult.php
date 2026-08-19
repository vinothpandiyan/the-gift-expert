<?php

namespace App\CommercialSourcing\Affiliate;

readonly class AffiliateUrlResult
{
    public function __construct(
        public ?string $url,
        public bool $ready,
        public string $strategy,
        public ?string $reasonCode,
    ) {}
}
