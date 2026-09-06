<?php

namespace App\Actions\Product;

use App\Enums\AffiliateLinkStatus;
use App\Models\BudgetRange;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class QueryPublishedProductsByFiltersAction
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
    public function execute(
        array $filters,
        bool $requireActiveAffiliate = false,
        bool $allowUnfiltered = false,
        bool $matchAllInterests = true,
    ): Builder {
        $interestIds = $this->idList($filters['interest_ids'] ?? []);
        $anyInterestIds = $this->idList($filters['any_interest_ids'] ?? []);

        $normalized = [
            'occasion_id' => $this->nullableId($filters['occasion_id'] ?? null),
            'occasion_ids' => $this->idList($filters['occasion_ids'] ?? []),
            'relationship_id' => $this->nullableId($filters['relationship_id'] ?? null),
            'relationship_ids' => $this->idList($filters['relationship_ids'] ?? []),
            'recipient_type_id' => $this->nullableId($filters['recipient_type_id'] ?? null),
            'recipient_type_ids' => $this->idList($filters['recipient_type_ids'] ?? []),
            'profession_id' => $this->nullableId($filters['profession_id'] ?? null),
            'profession_ids' => $this->idList($filters['profession_ids'] ?? []),
            'gift_type_id' => $this->nullableId($filters['gift_type_id'] ?? null),
            'gift_type_ids' => $this->idList($filters['gift_type_ids'] ?? []),
            'category_id' => $this->nullableId($filters['category_id'] ?? null),
            'category_ids' => $this->idList($filters['category_ids'] ?? []),
            'budget_range_id' => $this->nullableId($filters['budget_range_id'] ?? null),
            'interest_ids' => $interestIds,
            'any_interest_ids' => $anyInterestIds,
        ];

        if (! $this->hasAnyFilter($normalized) && ! $allowUnfiltered) {
            throw new InvalidArgumentException('At least one product filter is required.');
        }

        $query = Product::query()->published();

        if ($requireActiveAffiliate) {
            $query->whereHas('affiliateLinks', function (Builder $query): void {
                $query->where('status', AffiliateLinkStatus::Active);
            });
        }

        $this->constrainById($query, 'occasions', 'occasions.id', $normalized['occasion_id']);
        $this->constrainByIds($query, 'occasions', 'occasions.id', $normalized['occasion_ids']);
        $this->constrainById($query, 'relationships', 'relationships.id', $normalized['relationship_id']);
        $this->constrainByIds($query, 'relationships', 'relationships.id', $normalized['relationship_ids']);
        $this->constrainById($query, 'recipientTypes', 'recipient_types.id', $normalized['recipient_type_id']);
        $this->constrainByIds($query, 'recipientTypes', 'recipient_types.id', $normalized['recipient_type_ids']);
        $this->constrainById($query, 'professions', 'professions.id', $normalized['profession_id']);
        $this->constrainByIds($query, 'professions', 'professions.id', $normalized['profession_ids']);
        $this->constrainById($query, 'giftTypes', 'gift_types.id', $normalized['gift_type_id']);
        $this->constrainByIds($query, 'giftTypes', 'gift_types.id', $normalized['gift_type_ids']);
        $this->constrainById($query, 'categories', 'categories.id', $normalized['category_id']);
        $this->constrainByIds($query, 'categories', 'categories.id', $normalized['category_ids']);
        $this->constrainInterests($query, $interestIds, $matchAllInterests);
        $this->constrainByIds($query, 'interests', 'interests.id', $anyInterestIds);
        $this->constrainBudget($query, $normalized['budget_range_id']);

        return $query;
    }

    /**
     * @param  array{
     *     occasion_id: int|null,
     *     occasion_ids: list<int>,
     *     relationship_id: int|null,
     *     relationship_ids: list<int>,
     *     recipient_type_id: int|null,
     *     recipient_type_ids: list<int>,
     *     profession_id: int|null,
     *     profession_ids: list<int>,
     *     gift_type_id: int|null,
     *     gift_type_ids: list<int>,
     *     category_id: int|null,
     *     category_ids: list<int>,
     *     budget_range_id: int|null,
     *     interest_ids: list<int>,
     *     any_interest_ids: list<int>,
     * }  $filters
     */
    private function hasAnyFilter(array $filters): bool
    {
        return $filters['occasion_id'] !== null
            || $filters['occasion_ids'] !== []
            || $filters['relationship_id'] !== null
            || $filters['relationship_ids'] !== []
            || $filters['recipient_type_id'] !== null
            || $filters['recipient_type_ids'] !== []
            || $filters['profession_id'] !== null
            || $filters['profession_ids'] !== []
            || $filters['gift_type_id'] !== null
            || $filters['gift_type_ids'] !== []
            || $filters['category_id'] !== null
            || $filters['category_ids'] !== []
            || $filters['budget_range_id'] !== null
            || $filters['interest_ids'] !== []
            || $filters['any_interest_ids'] !== [];
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return list<int>
     */
    private function idList(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function constrainById(Builder $query, string $relation, string $column, ?int $id): void
    {
        if ($id === null) {
            return;
        }

        $query->whereHas($relation, fn (Builder $q) => $q->where($column, $id));
    }

    /**
     * @param  list<int>  $ids
     */
    private function constrainByIds(Builder $query, string $relation, string $column, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $query->whereHas($relation, fn (Builder $q) => $q->whereIn($column, $ids));
    }

    /**
     * @param  list<int>  $interestIds
     */
    private function constrainInterests(Builder $query, array $interestIds, bool $matchAllInterests): void
    {
        if ($interestIds === []) {
            return;
        }

        if ($matchAllInterests) {
            foreach ($interestIds as $interestId) {
                $query->whereHas('interests', fn (Builder $q) => $q->where('interests.id', $interestId));
            }

            return;
        }

        $query->whereHas('interests', fn (Builder $q) => $q->whereIn('interests.id', $interestIds));
    }

    private function constrainBudget(Builder $query, ?int $budgetRangeId): void
    {
        if ($budgetRangeId === null) {
            return;
        }

        $budget = BudgetRange::query()->find($budgetRangeId);

        if (! $budget instanceof BudgetRange) {
            throw new InvalidArgumentException("Budget range [{$budgetRangeId}] was not found.");
        }

        $query->where('price_currency', $budget->currency)
            ->whereNotNull('price_amount');

        if ($budget->min_amount !== null) {
            $query->where('price_amount', '>=', $budget->min_amount);
        }

        if ($budget->max_amount !== null) {
            $query->where('price_amount', '<=', $budget->max_amount);
        }
    }
}
