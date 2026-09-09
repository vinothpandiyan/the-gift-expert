<?php

namespace App\Actions\Discovery;

use App\DiscoveryRanking\RankedGift;
use Illuminate\Pagination\LengthAwarePaginator;

class PaginateRankedDiscoveryProductsAction
{
    /**
     * @param  list<RankedGift>  $ranked
     */
    public function execute(array $ranked, int $page, int $perPage, int $totalEligible, ?int $throughPage = null): LengthAwarePaginator
    {
        $page = max(1, $page);
        $throughPage = max($page, $throughPage ?? $page);
        $offset = ($page - 1) * $perPage;
        $limit = $perPage * ($throughPage - $page + 1);

        $pageItems = collect($ranked)
            ->slice($offset, $limit)
            ->map(fn (RankedGift $rankedGift) => $rankedGift->product)
            ->unique(fn ($product) => $product->id)
            ->values();

        return new LengthAwarePaginator(
            items: $pageItems,
            total: $totalEligible,
            perPage: $perPage,
            currentPage: $throughPage,
            options: [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }
}
