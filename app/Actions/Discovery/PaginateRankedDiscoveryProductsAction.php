<?php

namespace App\Actions\Discovery;

use App\DiscoveryRanking\RankedGift;
use Illuminate\Pagination\LengthAwarePaginator;

class PaginateRankedDiscoveryProductsAction
{
    /**
     * @param  list<RankedGift>  $ranked
     */
    public function execute(array $ranked, int $page, int $perPage, int $totalEligible): LengthAwarePaginator
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $pageItems = collect($ranked)
            ->slice($offset, $perPage)
            ->map(fn (RankedGift $rankedGift) => $rankedGift->product)
            ->values();

        return new LengthAwarePaginator(
            items: $pageItems,
            total: $totalEligible,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }
}
