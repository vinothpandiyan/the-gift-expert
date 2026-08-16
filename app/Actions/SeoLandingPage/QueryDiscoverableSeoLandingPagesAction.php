<?php

namespace App\Actions\SeoLandingPage;

use App\Models\Category;
use App\Models\SeoLandingPage;
use Illuminate\Support\Collection;

class QueryDiscoverableSeoLandingPagesAction
{
    /**
     * @param  array{
     *     relationship_id?: int|null,
     *     occasion_id?: int|null,
     *     recipient_type_id?: int|null,
     *     profession_id?: int|null,
     *     gift_type_id?: int|null,
     *     category_id?: int|null,
     *     interest_id?: int|null,
     * }  $filters
     * @return Collection<int, SeoLandingPage>
     */
    public function execute(array $filters, int $limit = 12): Collection
    {
        $query = SeoLandingPage::query()->discoverable();
        $applied = false;

        foreach (['relationship_id', 'occasion_id', 'recipient_type_id', 'profession_id', 'gift_type_id', 'category_id'] as $column) {
            $id = $this->nullableId($filters[$column] ?? null);

            if ($id === null) {
                continue;
            }

            $query->where($column, $id);
            $applied = true;
        }

        $interestId = $this->nullableId($filters['interest_id'] ?? null);

        if ($interestId !== null) {
            $query->whereHas('interests', fn ($interestQuery) => $interestQuery->where('interests.id', $interestId));
            $applied = true;
        }

        if (! $applied) {
            return collect();
        }

        return $query
            ->orderBy('sort_order')
            ->orderBy('heading')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, SeoLandingPage>
     */
    public function forCategory(Category $category, int $limit = 12): Collection
    {
        $canonicalIds = Category::query()
            ->where('parent_id', $category->id)
            ->whereNotNull('canonical_seo_landing_page_id')
            ->pluck('canonical_seo_landing_page_id');

        return SeoLandingPage::query()
            ->discoverable()
            ->where(function ($query) use ($category, $canonicalIds): void {
                $query->where('category_id', $category->id);

                if ($canonicalIds->isNotEmpty()) {
                    $query->orWhereIn('id', $canonicalIds);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('heading')
            ->limit($limit)
            ->get();
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
