<?php

namespace App\Actions\Discovery;

use App\Actions\Product\ApplyProductDiversityAction;
use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\Actions\Product\RankProductsForDiscoveryContextAction;
use App\DiscoveryRanking\DiscoveryRankingContext;
use App\DiscoveryRanking\RankedGift;
use App\RecipientEligibility\RecipientEligibilityConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class QueryRankedDiscoveryProductsAction
{
    public function __construct(
        private QueryPublishedProductsByFiltersAction $queryProducts,
        private RankProductsForDiscoveryContextAction $rankProducts,
        private ApplyProductDiversityAction $applyDiversity,
        private PaginateRankedDiscoveryProductsAction $paginateRanked,
        private ApplyRelevanceFirstPoolOrderingAction $orderPool,
    ) {}

    public function execute(DiscoveryRankingContext $context, int $page = 1, ?int $throughPage = null): LengthAwarePaginator
    {
        if (config('discovery_ranking.enabled') !== true) {
            return $this->fallbackPaginator($context, $page, $throughPage);
        }

        $perPage = max(1, (int) config('discovery_ranking.per_page', 12));
        $poolMax = max(1, (int) config('discovery_ranking.candidate_pool_max', 500));

        $query = $this->queryProducts->execute(
            $context->filters,
            $context->requireActiveAffiliate,
            false,
            $context->matchAllInterests,
            $context->recipientEligibility,
        );

        $totalEligible = (clone $query)->count();
        $rankingQuery = $this->boundedRankingQuery($query, $totalEligible, $poolMax, $context);

        $products = $rankingQuery
            ->with($this->scoringRelations())
            ->with($this->presentationRelations())
            ->get();

        if ($totalEligible > $poolMax && config('discovery_ranking.debug') === true) {
            Log::debug('discovery_ranking.pool_overflow', [
                'surface' => $context->surface,
                'total_eligible' => $totalEligible,
                'pool_max' => $poolMax,
            ]);
        }

        $ranked = $this->rankProducts->execute($products, $context);
        $diversified = $this->applyDiversity->execute($ranked);

        if ($totalEligible > $poolMax) {
            $diversified = $this->appendOverflowTail($query, $diversified, $poolMax, $totalEligible, $context);
        }

        return $this->paginateRanked->execute($diversified, $page, $perPage, $totalEligible, $throughPage);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function scoringRelations(): array
    {
        return [
            'categories',
            'relationships:id',
            'occasions:id',
            'interests:id',
            'recipientTypes:id',
            'professions:id',
            'giftTypes:id',
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function presentationRelations(): array
    {
        return [
            'categories',
            'giftTypes:id,slug',
            'images' => fn ($query) => $query
                ->orderByDesc('is_primary')
                ->orderBy('sort_order'),
            'affiliateLinks' => fn ($query) => $query
                ->active()
                ->with('merchant')
                ->orderByDesc('is_primary'),
        ];
    }

    private function boundedRankingQuery(Builder $query, int $totalEligible, int $poolMax, DiscoveryRankingContext $context): Builder
    {
        if ($totalEligible <= $poolMax) {
            return $query;
        }

        // Clone: the caller reuses $query for the overflow tail, which must not inherit this limit.
        return $this->orderPoolForOverflow(clone $query, $context)->limit($poolMax);
    }

    /**
     * Pool truncation order. Recency by default; relevance-first (SQL pre-score,
     * then recency) when the pool-ordering flag is on. The overflow tail must use
     * the same order so pool and tail partition the eligible set consistently.
     */
    private function orderPoolForOverflow(Builder $query, DiscoveryRankingContext $context): Builder
    {
        if (RecipientEligibilityConfig::poolOrdering()) {
            return $this->orderPool->execute($query, $context);
        }

        return $query
            ->orderByDesc('published_at')
            ->orderBy('id');
    }

    /**
     * @param  list<RankedGift>  $ranked
     * @return list<RankedGift>
     */
    private function appendOverflowTail(Builder $query, array $ranked, int $poolMax, int $totalEligible, DiscoveryRankingContext $context): array
    {
        $rankedIds = collect($ranked)->map(fn (RankedGift $gift) => $gift->product->id)->all();

        $tailProducts = $this->orderPoolForOverflow(clone $query, $context)
            ->offset($poolMax)
            ->limit(max(1, $totalEligible - $poolMax))
            ->with($this->presentationRelations())
            ->get();

        foreach ($tailProducts as $product) {
            if (in_array($product->id, $rankedIds, true)) {
                continue;
            }

            $ranked[] = new RankedGift($product, 0.0, []);
            $rankedIds[] = $product->id;
        }

        return $ranked;
    }

    private function fallbackPaginator(DiscoveryRankingContext $context, int $page, ?int $throughPage = null): LengthAwarePaginator
    {
        $perPage = max(1, (int) config('discovery_ranking.per_page', 12));
        $page = max(1, $page);
        $throughPage = max($page, $throughPage ?? $page);
        $offset = ($page - 1) * $perPage;
        $limit = $perPage * ($throughPage - $page + 1);

        $query = $this->queryProducts->execute(
            $context->filters,
            $context->requireActiveAffiliate,
            false,
            $context->matchAllInterests,
            $context->recipientEligibility,
        )
            ->with($this->presentationRelations())
            ->orderByDesc('published_at')
            ->orderBy('id');

        $total = (clone $query)->count();
        $items = $query->offset($offset)->limit($limit)->get()->unique('id')->values();

        return new LengthAwarePaginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $throughPage,
            options: [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }
}
