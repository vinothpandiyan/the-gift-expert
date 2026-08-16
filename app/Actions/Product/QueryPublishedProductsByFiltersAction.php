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
     *     relationship_id?: int|null,
     *     recipient_type_id?: int|null,
     *     profession_id?: int|null,
     *     gift_type_id?: int|null,
     *     category_id?: int|null,
     *     budget_range_id?: int|null,
     *     interest_ids?: list<int>|null,
     * }  $filters
     */
    public function execute(
        array $filters,
        bool $requireActiveAffiliate = false,
        bool $allowUnfiltered = false,
        bool $matchAllInterests = true,
    ): Builder {
        $interestIds = collect($filters['interest_ids'] ?? [])
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $normalized = [
            'occasion_id' => $this->nullableId($filters['occasion_id'] ?? null),
            'relationship_id' => $this->nullableId($filters['relationship_id'] ?? null),
            'recipient_type_id' => $this->nullableId($filters['recipient_type_id'] ?? null),
            'profession_id' => $this->nullableId($filters['profession_id'] ?? null),
            'gift_type_id' => $this->nullableId($filters['gift_type_id'] ?? null),
            'category_id' => $this->nullableId($filters['category_id'] ?? null),
            'budget_range_id' => $this->nullableId($filters['budget_range_id'] ?? null),
            'interest_ids' => $interestIds,
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
        $this->constrainById($query, 'relationships', 'relationships.id', $normalized['relationship_id']);
        $this->constrainById($query, 'recipientTypes', 'recipient_types.id', $normalized['recipient_type_id']);
        $this->constrainById($query, 'professions', 'professions.id', $normalized['profession_id']);
        $this->constrainById($query, 'giftTypes', 'gift_types.id', $normalized['gift_type_id']);
        $this->constrainById($query, 'categories', 'categories.id', $normalized['category_id']);
        $this->constrainInterests($query, $interestIds, $matchAllInterests);
        $this->constrainBudget($query, $normalized['budget_range_id']);

        return $query;
    }

    /**
     * @param  array{
     *     occasion_id: int|null,
     *     relationship_id: int|null,
     *     recipient_type_id: int|null,
     *     profession_id: int|null,
     *     gift_type_id: int|null,
     *     category_id: int|null,
     *     budget_range_id: int|null,
     *     interest_ids: list<int>,
     * }  $filters
     */
    private function hasAnyFilter(array $filters): bool
    {
        return $filters['occasion_id'] !== null
            || $filters['relationship_id'] !== null
            || $filters['recipient_type_id'] !== null
            || $filters['profession_id'] !== null
            || $filters['gift_type_id'] !== null
            || $filters['category_id'] !== null
            || $filters['budget_range_id'] !== null
            || $filters['interest_ids'] !== [];
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function constrainById(Builder $query, string $relation, string $column, ?int $id): void
    {
        if ($id === null) {
            return;
        }

        $query->whereHas($relation, fn (Builder $q) => $q->where($column, $id));
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
