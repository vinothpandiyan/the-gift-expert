<?php

namespace App\Actions\Discovery;

use App\Actions\Product\ApplyProductDiversityAction;
use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\Actions\Product\RankProductsForDiscoveryContextAction;
use App\DiscoveryRanking\DiscoveryRankingContext;
use App\DiscoveryRanking\RankedGift;
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
    ) {}

    public function execute(DiscoveryRankingContext $context, int $page = 1): LengthAwarePaginator
    {
        if (config('discovery_ranking.enabled') !== true) {
            return $this->fallbackPaginator($context, $page);
        }

        $perPage = max(1, (int) config('discovery_ranking.per_page', 12));
        $poolMax = max(1, (int) config('discovery_ranking.candidate_pool_max', 500));

        $query = $this->queryProducts->execute(
            $context->filters,
            $context->requireActiveAffiliate,
            false,
            $context->matchAllInterests,
        );

        $totalEligible = (clone $query)->count();
        $rankingQuery = $this->boundedRankingQuery($query, $totalEligible, $poolMax);

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
            $diversified = $this->appendOverflowTail($query, $diversified, $poolMax);
        }

        return $this->paginateRanked->execute($diversified, $page, $perPage, $totalEligible);
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
            'images' => fn ($query) => $query
                ->orderByDesc('is_primary')
                ->orderBy('sort_order'),
            'affiliateLinks' => fn ($query) => $query
                ->active()
                ->with('merchant')
                ->orderByDesc('is_primary'),
        ];
    }

    private function boundedRankingQuery(Builder $query, int $totalEligible, int $poolMax): Builder
    {
        if ($totalEligible <= $poolMax) {
            return $query;
        }

        return $query
            ->orderByDesc('published_at')
            ->orderBy('id')
            ->limit($poolMax);
    }

    /**
     * @param  list<RankedGift>  $ranked
     * @return list<RankedGift>
     */
    private function appendOverflowTail(Builder $query, array $ranked, int $poolMax): array
    {
        $rankedIds = collect($ranked)->map(fn (RankedGift $gift) => $gift->product->id)->all();

        $tailProducts = (clone $query)
            ->orderByDesc('published_at')
            ->orderBy('id')
            ->offset($poolMax)
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

    private function fallbackPaginator(DiscoveryRankingContext $context, int $page): LengthAwarePaginator
    {
        $perPage = max(1, (int) config('discovery_ranking.per_page', 12));

        return $this->queryProducts->execute(
            $context->filters,
            $context->requireActiveAffiliate,
            false,
            $context->matchAllInterests,
        )
            ->with($this->presentationRelations())
            ->orderByDesc('published_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
