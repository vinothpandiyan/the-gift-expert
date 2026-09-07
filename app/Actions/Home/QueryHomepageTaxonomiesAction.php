<?php

namespace App\Actions\Home;

use App\Models\BudgetRange;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Relationship;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class QueryHomepageTaxonomiesAction
{
    public const RELATIONSHIP_LIMIT = 10;

    public const OCCASION_LIMIT = 8;

    public const INTEREST_LIMIT = 10;

    /**
     * @return array{
     *     relationships: Collection<int, Relationship>,
     *     occasions: Collection<int, Occasion>,
     *     interests: Collection<int, Interest>,
     *     budgetRanges: Collection<int, BudgetRange>,
     *     returnGifts: GiftType|null,
     * }
     */
    public function execute(): array
    {
        return [
            'relationships' => $this->activeOrdered(Relationship::query(), self::RELATIONSHIP_LIMIT),
            'occasions' => $this->activeOrdered(Occasion::query(), self::OCCASION_LIMIT),
            'interests' => $this->activeOrdered(Interest::query(), self::INTEREST_LIMIT),
            'budgetRanges' => $this->activeOrdered(BudgetRange::query()),
            'returnGifts' => GiftType::query()
                ->where('slug', 'return-gifts')
                ->where('is_active', true)
                ->first(),
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Collection<int, TModel>
     */
    private function activeOrdered(Builder $query, ?int $limit = null): Collection
    {
        $query
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }
}
