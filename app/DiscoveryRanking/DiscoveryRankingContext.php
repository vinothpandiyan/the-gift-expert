<?php

namespace App\DiscoveryRanking;

final class DiscoveryRankingContext
{
    /**
     * @param  array{
     *     occasion_id?: int|null,
     *     relationship_id?: int|null,
     *     recipient_type_id?: int|null,
     *     profession_id?: int|null,
     *     gift_type_id?: int|null,
     *     category_id?: int|null,
     *     budget_range_id?: int|null,
     *     interest_ids?: list<int>|null,
     * }  $filters
     */
    public function __construct(
        public readonly string $surface,
        public readonly array $filters,
        public readonly bool $matchAllInterests = true,
        public readonly bool $requireActiveAffiliate = false,
    ) {}
}
