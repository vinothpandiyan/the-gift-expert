<?php

namespace App\Actions\Product;

use App\DiscoveryRanking\DiscoveryRankingContext;
use App\DiscoveryRanking\RankedGift;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RankProductsForDiscoveryContextAction
{
    /**
     * @param  Collection<int, Product>  $products
     * @return list<RankedGift>
     */
    public function execute(Collection $products, DiscoveryRankingContext $context): array
    {
        $ranked = $products
            ->map(fn (Product $product): RankedGift => $this->scoreProduct($product, $context))
            ->sort(fn (RankedGift $left, RankedGift $right): int => $this->compareRanked($left, $right))
            ->values()
            ->all();

        if (config('discovery_ranking.debug') === true) {
            foreach ($ranked as $rankedGift) {
                Log::debug('discovery_ranking.score', [
                    'product_id' => $rankedGift->product->id,
                    'score' => $rankedGift->score,
                    'breakdown' => $rankedGift->scoreBreakdown,
                    'surface' => $context->surface,
                ]);
            }
        }

        return $ranked;
    }

    private function scoreProduct(Product $product, DiscoveryRankingContext $context): RankedGift
    {
        $breakdown = [];
        $filters = $context->filters;

        if ($this->matchesAnyId($product, 'relationships', $this->dimensionIds($filters, 'relationship_id', 'relationship_ids'))) {
            $breakdown['relationship_match'] = $this->weight('relationship_match');
        }

        if ($this->matchesAnyId($product, 'occasions', $this->dimensionIds($filters, 'occasion_id', 'occasion_ids'))) {
            $breakdown['occasion_match'] = $this->weight('occasion_match');
        }

        $interestIds = $this->normalizedInterestIds([
            ...($filters['interest_ids'] ?? []),
            ...($filters['any_interest_ids'] ?? []),
        ]);

        if ($interestIds !== []) {
            $overlap = $product->relationLoaded('interests')
                ? $product->interests->whereIn('id', $interestIds)->count()
                : $product->interests()->whereIn('interests.id', $interestIds)->count();

            if ($overlap > 0) {
                $breakdown['interest_match'] = min(
                    $overlap * $this->weight('interest_match'),
                    $this->weight('interest_match_max'),
                );
            }
        }

        if ($this->matchesAnyId($product, 'recipientTypes', $this->dimensionIds($filters, 'recipient_type_id', 'recipient_type_ids'))) {
            $breakdown['recipient_type_match'] = $this->weight('recipient_type_match');
        }

        if ($this->matchesAnyId($product, 'professions', $this->dimensionIds($filters, 'profession_id', 'profession_ids'))) {
            $breakdown['profession_match'] = $this->weight('profession_match');
        }

        if ($this->matchesAnyId($product, 'giftTypes', $this->dimensionIds($filters, 'gift_type_id', 'gift_type_ids'))) {
            $breakdown['gift_type_match'] = $this->weight('gift_type_match');
        }

        $categoryIds = $this->dimensionIds($filters, 'category_id', 'category_ids');

        if ($categoryIds !== []) {
            $categories = $product->relationLoaded('categories')
                ? $product->categories
                : $product->categories()->get();

            $primaryCategory = $categories->first(fn ($category) => (bool) $category->pivot?->is_primary);

            if ($primaryCategory !== null && in_array((int) $primaryCategory->id, $categoryIds, true)) {
                $breakdown['primary_category_match'] = $this->weight('primary_category_match');
            } elseif ($categories->contains(fn ($category) => in_array((int) $category->id, $categoryIds, true))) {
                $breakdown['secondary_category_match'] = $this->weight('secondary_category_match');
            }
        }

        if ($product->is_featured) {
            $breakdown['featured'] = $this->weight('featured');
        }

        if ($product->price_amount !== null) {
            $breakdown['price_present'] = $this->weight('price_present');
        }

        $breadthPenalty = $this->breadthPenalty($product, $context);

        if ($breadthPenalty > 0) {
            $breakdown['breadth_penalty'] = -$breadthPenalty;
        }

        $score = (float) array_sum($breakdown);

        return new RankedGift($product, $score, $breakdown);
    }

    private function breadthPenalty(Product $product, DiscoveryRankingContext $context): int
    {
        $dimension = config('discovery_ranking.surfaces.'.$context->surface.'.breadth_dimension');

        if (! is_string($dimension) || $dimension === '') {
            return 0;
        }

        $tagCount = match ($dimension) {
            'relationships' => $product->relationLoaded('relationships')
                ? $product->relationships->count()
                : $product->relationships()->count(),
            'occasions' => $product->relationLoaded('occasions')
                ? $product->occasions->count()
                : $product->occasions()->count(),
            default => 0,
        };

        $threshold = (int) config('discovery_ranking.breadth.threshold', 4);
        $perTag = (int) config('discovery_ranking.breadth.per_tag', 1);
        $maxPenalty = (int) config('discovery_ranking.breadth.max_penalty', 5);

        $extraTags = max(0, $tagCount - $threshold);

        return min($maxPenalty, $extraTags * $perTag);
    }

    private function compareRanked(RankedGift $left, RankedGift $right): int
    {
        if ($left->score !== $right->score) {
            return $right->score <=> $left->score;
        }

        $leftFeatured = $left->product->is_featured ? 1 : 0;
        $rightFeatured = $right->product->is_featured ? 1 : 0;

        if ($leftFeatured !== $rightFeatured) {
            return $rightFeatured <=> $leftFeatured;
        }

        $leftPublished = $left->product->published_at?->getTimestamp() ?? 0;
        $rightPublished = $right->product->published_at?->getTimestamp() ?? 0;

        if ($leftPublished !== $rightPublished) {
            return $rightPublished <=> $leftPublished;
        }

        return $left->product->id <=> $right->product->id;
    }

    private function weight(string $key): int
    {
        return (int) config('discovery_ranking.weights.'.$key, 0);
    }

    /**
     * @return list<int>
     */
    private function normalizedInterestIds(mixed $interestIds): array
    {
        if (! is_array($interestIds)) {
            return [];
        }

        return collect($interestIds)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function dimensionIds(array $filters, string $scalarKey, string $listKey): array
    {
        $ids = $this->normalizedInterestIds($filters[$listKey] ?? []);
        $scalar = $this->nullableId($filters[$scalarKey] ?? null);

        if ($scalar !== null) {
            $ids[] = $scalar;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $ids
     */
    private function matchesAnyId(Product $product, string $relation, array $ids): bool
    {
        if ($ids === []) {
            return false;
        }

        if ($product->relationLoaded($relation)) {
            return $product->{$relation}->contains(fn ($record) => in_array((int) $record->id, $ids, true));
        }

        return $product->{$relation}()->whereKey($ids)->exists();
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
