<?php

namespace App\Actions\Discovery;

use App\DiscoveryRanking\DiscoveryRankingContext;
use App\RecipientEligibility\RecipientEligibilityConfig;
use Illuminate\Database\Eloquent\Builder;

/**
 * Orders the candidate pool by an SQL pre-score that mirrors the ranking weights
 * (config discovery_ranking.weights) instead of publication date alone, so that
 * truncating to `candidate_pool_max` drops the least relevant products first.
 *
 * Selected interest/occasion matches count as much as an explicit relationship
 * match, and an inferred relationship match carries its own (lower) weight, so a
 * strongly relevant inferred candidate is never ranked behind a generic explicit
 * one. The breadth penalty is not part of the pre-score; the exact ranking is
 * still computed in PHP over the retained pool.
 */
class ApplyRelevanceFirstPoolOrderingAction
{
    public function execute(Builder $query, DiscoveryRankingContext $context): Builder
    {
        [$sql, $bindings] = $this->preScoreSql($context);

        return $query
            ->orderByRaw($sql.' DESC', $bindings)
            ->orderByDesc('products.published_at')
            ->orderBy('products.id');
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function preScoreSql(DiscoveryRankingContext $context): array
    {
        $filters = $context->filters;
        $parts = [];
        $bindings = [];

        $interestIds = $this->ids($filters, ['interest_ids', 'any_interest_ids']);

        if ($interestIds !== []) {
            $parts[] = '(SELECT LEAST(COUNT(*) * ?, ?) FROM interest_product WHERE interest_product.product_id = products.id AND interest_product.interest_id IN ('.$this->placeholders($interestIds).'))';
            array_push($bindings, $this->weight('interest_match'), $this->weight('interest_match_max'), ...$interestIds);
        }

        $occasionIds = $this->ids($filters, ['occasion_id', 'occasion_ids']);

        if ($occasionIds !== []) {
            $parts[] = $this->existsCase('occasion_product', 'occasion_id', $occasionIds, $this->weight('occasion_match'), $bindings);
        }

        $relationshipIds = $this->ids($filters, ['relationship_id', 'relationship_ids']);

        if ($relationshipIds !== []) {
            $inferred = $context->recipientEligibility?->infersAny() === true
                ? RecipientEligibilityConfig::inferredWeight()
                : 0;
            $parts[] = $this->existsCase('relationship_product', 'relationship_id', $relationshipIds, $this->weight('relationship_match'), $bindings, $inferred);
        }

        $recipientTypeIds = $this->ids($filters, ['recipient_type_id', 'recipient_type_ids']);

        if ($recipientTypeIds !== []) {
            $parts[] = $this->existsCase('recipient_type_product', 'recipient_type_id', $recipientTypeIds, $this->weight('recipient_type_match'), $bindings);
        }

        $professionIds = $this->ids($filters, ['profession_id', 'profession_ids']);

        if ($professionIds !== []) {
            $parts[] = $this->existsCase('profession_product', 'profession_id', $professionIds, $this->weight('profession_match'), $bindings);
        }

        $giftTypeIds = $this->ids($filters, ['gift_type_id', 'gift_type_ids']);

        if ($giftTypeIds !== []) {
            $parts[] = $this->existsCase('gift_type_product', 'gift_type_id', $giftTypeIds, $this->weight('gift_type_match'), $bindings);
        }

        $categoryIds = $this->ids($filters, ['category_id', 'category_ids']);

        if ($categoryIds !== []) {
            $placeholders = $this->placeholders($categoryIds);
            $parts[] = 'CASE'
                .' WHEN EXISTS (SELECT 1 FROM category_product WHERE category_product.product_id = products.id AND category_product.is_primary = 1 AND category_product.category_id IN ('.$placeholders.')) THEN ?'
                .' WHEN EXISTS (SELECT 1 FROM category_product WHERE category_product.product_id = products.id AND category_product.category_id IN ('.$placeholders.')) THEN ?'
                .' ELSE 0 END';
            array_push(
                $bindings,
                ...$categoryIds,
                ...[$this->weight('primary_category_match')],
                ...$categoryIds,
                ...[$this->weight('secondary_category_match')],
            );
        }

        $parts[] = 'CASE WHEN products.is_featured = 1 THEN '.$this->weight('featured').' ELSE 0 END';
        $parts[] = 'CASE WHEN products.price_amount IS NOT NULL THEN '.$this->weight('price_present').' ELSE 0 END';

        return ['('.implode(' + ', $parts).')', $bindings];
    }

    /**
     * @param  list<int>  $ids
     * @param  list<mixed>  $bindings
     */
    private function existsCase(string $table, string $column, array $ids, int $matchWeight, array &$bindings, int $elseWeight = 0): string
    {
        array_push($bindings, ...$ids, ...[$matchWeight, $elseWeight]);

        return 'CASE WHEN EXISTS (SELECT 1 FROM '.$table.' WHERE '.$table.'.product_id = products.id AND '.$table.'.'.$column.' IN ('.$this->placeholders($ids).')) THEN ? ELSE ? END';
    }

    /**
     * @param  list<int>  $ids
     */
    private function placeholders(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $keys
     * @return list<int>
     */
    private function ids(array $filters, array $keys): array
    {
        $ids = [];

        foreach ($keys as $key) {
            $value = $filters[$key] ?? null;

            foreach (is_array($value) ? $value : [$value] as $id) {
                if ($id !== null && $id !== '') {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function weight(string $key): int
    {
        return (int) config('discovery_ranking.weights.'.$key, 0);
    }
}
