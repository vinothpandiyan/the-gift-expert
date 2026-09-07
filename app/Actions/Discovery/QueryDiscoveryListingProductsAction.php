<?php

namespace App\Actions\Discovery;

use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\DiscoveryRanking\DiscoveryRankingContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class QueryDiscoveryListingProductsAction
{
    public function __construct(
        private QueryPublishedProductsByFiltersAction $queryProducts,
        private QueryRankedDiscoveryProductsAction $queryRanked,
    ) {}

    public function execute(
        DiscoveryListingContext $context,
        DiscoveryListingQueryState $state,
        int $page = 1,
    ): LengthAwarePaginator {
        $filters = $state->toProductFilters($context);
        $perPage = max(1, (int) config('discovery_ranking.per_page', 12));
        $page = max(1, $page);

        if (! $this->hasProductFilters($filters)) {
            return $this->withListingQuery(
                new LengthAwarePaginator([], 0, $perPage, $page),
                $state,
            );
        }

        if ($state->isRecommended()) {
            $ranked = $this->queryRanked->execute(
                new DiscoveryRankingContext(
                    surface: $context->surface,
                    filters: $filters,
                    matchAllInterests: true,
                ),
                $page,
            );

            return $this->withListingQuery($ranked, $state);
        }

        $query = $this->queryProducts->execute($filters, false, false, true)
            ->with($this->presentationRelations());

        $this->applySort($query, $state->sort);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return $this->withListingQuery($paginator, $state);
    }

    public function count(DiscoveryListingContext $context, DiscoveryListingQueryState $state): int
    {
        $filters = $state->toProductFilters($context);

        if (! $this->hasProductFilters($filters)) {
            return 0;
        }

        return $this->queryProducts
            ->execute($filters, false, false, true)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function hasProductFilters(array $filters): bool
    {
        foreach ($filters as $value) {
            if (is_array($value) && $value !== []) {
                return true;
            }

            if (! is_array($value) && $value !== null && $value !== '') {
                return true;
            }
        }

        return false;
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

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            DiscoveryListingQueryState::SORT_PRICE_ASC => $query
                ->orderByRaw('price_amount IS NULL')
                ->orderBy('price_amount')
                ->orderBy('id'),
            DiscoveryListingQueryState::SORT_PRICE_DESC => $query
                ->orderByRaw('price_amount IS NULL')
                ->orderByDesc('price_amount')
                ->orderBy('id'),
            default => $query
                ->orderByDesc('published_at')
                ->orderBy('id'),
        };
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return LengthAwarePaginator<int, mixed>
     */
    private function withListingQuery(LengthAwarePaginator $paginator, DiscoveryListingQueryState $state): LengthAwarePaginator
    {
        $paginator->withPath(request()->url());
        $paginator->appends($state->toPaginatorQuery());

        return $paginator;
    }
}
