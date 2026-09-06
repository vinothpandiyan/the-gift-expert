<?php

namespace App\DiscoveryRanking;

final class DiscoveryRankingContext
{
    /**
     * @param  array{
     *     occasion_id?: int|null,
     *     occasion_ids?: list<int>|null,
     *     relationship_id?: int|null,
     *     relationship_ids?: list<int>|null,
     *     recipient_type_id?: int|null,
     *     recipient_type_ids?: list<int>|null,
     *     profession_id?: int|null,
     *     profession_ids?: list<int>|null,
     *     gift_type_id?: int|null,
     *     gift_type_ids?: list<int>|null,
     *     category_id?: int|null,
     *     category_ids?: list<int>|null,
     *     budget_range_id?: int|null,
     *     interest_ids?: list<int>|null,
     *     any_interest_ids?: list<int>|null,
     * }  $filters
     */
    public function __construct(
        public readonly string $surface,
        public readonly array $filters,
        public readonly bool $matchAllInterests = true,
        public readonly bool $requireActiveAffiliate = false,
    ) {}
}
