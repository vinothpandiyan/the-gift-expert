<?php

namespace App\DiscoveryRanking;

use App\Models\Product;

final class RankedGift
{
    /**
     * @param  array<string, float|int>  $scoreBreakdown
     */
    public function __construct(
        public readonly Product $product,
        public readonly float $score,
        public readonly array $scoreBreakdown,
    ) {}
}
