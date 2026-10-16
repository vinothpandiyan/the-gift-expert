<?php

namespace App\Actions\Discovery;

use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\DiscoveryRanking\DiscoveryRankingContext;
use App\RecipientEligibility\RecipientEligibilityConfig;
use App\RecipientEligibility\RecipientEligibilityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

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
        ?int $throughPage = null,
    ): LengthAwarePaginator {
        $filters = $state->toProductFilters($context);
        $eligibility = RecipientEligibilityConfig::context($context->surface);
        $perPage = max(1, (int) config('discovery_ranking.per_page', 12));
        $page = max(1, $page);
        $throughPage = max($page, $throughPage ?? $page);

        if (! $this->hasProductFilters($filters)) {
            return $this->withListingQuery(
                new LengthAwarePaginator([], 0, $perPage, $throughPage),
                $state,
            );
        }

        if ($state->isRecommended()) {
            $ranked = $this->queryRanked->execute(
                new DiscoveryRankingContext(
                    surface: $context->surface,
                    filters: $filters,
                    matchAllInterests: true,
                    requireActiveAffiliate: $this->requireActiveAffiliate(),
                    recipientEligibility: $eligibility,
                ),
                $page,
                $throughPage,
            );

            $this->logShadow($context, $filters, $eligibility, $ranked->total());

            return $this->withListingQuery($ranked, $state);
        }

        $query = $this->queryProducts->execute($filters, $this->requireActiveAffiliate(), false, true, $eligibility)
            ->with($this->presentationRelations());

        $this->applySort($query, $state->sort);

        $offset = ($page - 1) * $perPage;
        $limit = $perPage * ($throughPage - $page + 1);
        $total = (clone $query)->count();
        $items = $query->offset($offset)->limit($limit)->get()->unique('id')->values();
        $this->logShadow($context, $filters, $eligibility, $total);

        $paginator = new LengthAwarePaginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $throughPage,
            options: [
                'path' => request()->url(),
                'pageName' => 'page',
            ],
        );

        return $this->withListingQuery($paginator, $state);
    }

    public function count(DiscoveryListingContext $context, DiscoveryListingQueryState $state): int
    {
        $filters = $state->toProductFilters($context);

        if (! $this->hasProductFilters($filters)) {
            return 0;
        }

        return $this->queryProducts
            ->execute($filters, $this->requireActiveAffiliate(), false, true, RecipientEligibilityConfig::context($context->surface))
            ->count();
    }

    /**
     * Sampled runtime shadow: logs strict vs would-be-eligible totals for relationship
     * listings while eligibility is not live. Never alters results and never throws.
     *
     * @param  array<string, mixed>  $filters
     */
    private function logShadow(DiscoveryListingContext $context, array $filters, ?RecipientEligibilityContext $live, int $liveTotal): void
    {
        if ($live !== null || ! RecipientEligibilityConfig::shadowEnabled()) {
            return;
        }

        if (! isset($filters['relationship_id']) && empty($filters['relationship_ids'])) {
            return;
        }

        if (mt_rand() / mt_getrandmax() > RecipientEligibilityConfig::shadowSampleRate()) {
            return;
        }

        try {
            $eligibleTotal = $this->queryProducts
                ->execute($filters, $this->requireActiveAffiliate(), false, true, RecipientEligibilityConfig::shadowContext($context->surface))
                ->count();

            Log::info('recipient_eligibility.shadow', [
                'surface' => $context->surface,
                'browse_context' => $context->browseContext,
                'strict_total' => $liveTotal,
                'eligible_total' => $eligibleTotal,
                'delta' => $eligibleTotal - $liveTotal,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('recipient_eligibility.shadow_failed', ['message' => $exception->getMessage()]);
        }
    }

    private function requireActiveAffiliate(): bool
    {
        return config('discovery_ranking.listing_require_active_affiliate') === true;
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
