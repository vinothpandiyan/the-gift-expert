<?php

namespace App\Actions\Discovery;

use App\Actions\Product\ApplyProductDiversityAction;
use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\Actions\Product\RankProductsForDiscoveryContextAction;
use App\DiscoveryRanking\DiscoveryRankingContext;
use App\DiscoveryRanking\RankedGift;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;

class QueryRelatedGiftsAction
{
    public const LIMIT = 4;

    public const POOL_MAX = 24;

    public function __construct(
        private QueryPublishedProductsByFiltersAction $queryProducts,
        private RankProductsForDiscoveryContextAction $rankProducts,
        private ApplyProductDiversityAction $applyDiversity,
    ) {}

    /**
     * @return Collection<int, Product>
     */
    public function execute(Product $product, int $limit = self::LIMIT): Collection
    {
        $this->ensureTaxonomiesLoaded($product);

        $hardFilter = $this->hardFilter($product);

        if ($hardFilter === []) {
            return collect();
        }

        $limit = max(1, $limit);

        $products = $this->queryProducts
            ->execute($hardFilter, true)
            ->where('products.id', '!=', $product->id)
            ->with($this->presentationRelations())
            ->orderByDesc('published_at')
            ->orderBy('id')
            ->limit(self::POOL_MAX)
            ->get();

        if ($products->isEmpty()) {
            return collect();
        }

        if (config('discovery_ranking.enabled') === true) {
            $ranked = $this->rankProducts->execute(
                $products,
                new DiscoveryRankingContext(
                    surface: 'gift_detail',
                    filters: $this->scoringFilters($product),
                    matchAllInterests: true,
                    requireActiveAffiliate: true,
                ),
            );
            $products = collect($this->applyDiversity->execute($ranked))
                ->map(fn (RankedGift $rankedGift): Product => $rankedGift->product)
                ->values();
        }

        return $products->take($limit)->values();
    }

    /**
     * @return array<string, int|list<int>>
     */
    private function hardFilter(Product $product): array
    {
        $primaryCategory = $this->primaryCategory($product);

        if ($primaryCategory instanceof Category) {
            return ['category_id' => (int) $primaryCategory->id];
        }

        $relationship = $this->activeRecords($product->relationships)->first();
        if ($relationship !== null) {
            return ['relationship_id' => (int) $relationship->id];
        }

        $occasion = $this->activeRecords($product->occasions)->first();
        if ($occasion !== null) {
            return ['occasion_id' => (int) $occasion->id];
        }

        $interest = $this->activeRecords($product->interests)->first();
        if ($interest !== null) {
            return ['interest_ids' => [(int) $interest->id]];
        }

        $giftType = $this->activeRecords($product->giftTypes)->first();
        if ($giftType !== null) {
            return ['gift_type_id' => (int) $giftType->id];
        }

        return [];
    }

    /**
     * @return array<string, int|list<int>>
     */
    private function scoringFilters(Product $product): array
    {
        $filters = [];

        $primaryCategory = $this->primaryCategory($product);
        if ($primaryCategory instanceof Category) {
            $filters['category_id'] = (int) $primaryCategory->id;
        }

        $relationshipIds = $this->ids($this->activeRecords($product->relationships));
        if ($relationshipIds !== []) {
            $filters['relationship_ids'] = $relationshipIds;
        }

        $occasionIds = $this->ids($this->activeRecords($product->occasions));
        if ($occasionIds !== []) {
            $filters['occasion_ids'] = $occasionIds;
        }

        $interestIds = $this->ids($this->activeRecords($product->interests));
        if ($interestIds !== []) {
            $filters['interest_ids'] = $interestIds;
        }

        $recipientTypeIds = $this->ids($this->activeRecords($product->recipientTypes));
        if ($recipientTypeIds !== []) {
            $filters['recipient_type_ids'] = $recipientTypeIds;
        }

        $professionIds = $this->ids($this->activeRecords($product->professions));
        if ($professionIds !== []) {
            $filters['profession_ids'] = $professionIds;
        }

        $giftTypeIds = $this->ids($this->activeRecords($product->giftTypes));
        if ($giftTypeIds !== []) {
            $filters['gift_type_ids'] = $giftTypeIds;
        }

        return $filters;
    }

    private function primaryCategory(Product $product): ?Category
    {
        $primary = $this->activeRecords($product->categories)->first(
            fn (Category $category): bool => (bool) ($category->pivot->is_primary ?? false),
        );

        return $primary instanceof Category ? $primary : null;
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @return Collection<int, mixed>
     */
    private function activeRecords(Collection $records): Collection
    {
        return $records
            ->filter(fn ($record): bool => (bool) ($record->is_active ?? true))
            ->values();
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @return list<int>
     */
    private function ids(Collection $records): array
    {
        return $records
            ->map(fn ($record): int => (int) $record->id)
            ->unique()
            ->values()
            ->all();
    }

    private function ensureTaxonomiesLoaded(Product $product): void
    {
        $relations = [
            'categories',
            'relationships',
            'occasions',
            'interests',
            'recipientTypes',
            'professions',
            'giftTypes',
        ];

        $missing = array_values(array_filter(
            $relations,
            fn (string $relation): bool => ! $product->relationLoaded($relation),
        ));

        if ($missing !== []) {
            $product->load($missing);
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    private function presentationRelations(): array
    {
        return [
            'categories',
            'relationships',
            'occasions',
            'interests',
            'recipientTypes',
            'professions',
            'giftTypes',
            'images' => fn ($query) => $query
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id'),
            'affiliateLinks' => fn ($query) => $query
                ->active()
                ->with('merchant')
                ->orderByDesc('is_primary')
                ->orderBy('id'),
        ];
    }
}
