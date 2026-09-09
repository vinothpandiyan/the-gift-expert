<?php

namespace App\Actions\Product;

use App\Actions\Category\IsAcceptableMerchandisingCategoryAction;
use App\CuratedCatalog\NormalizedProductCategoryAssignments;
use App\Models\Category;
use InvalidArgumentException;

class NormalizeProductCategoryAssignmentsAction
{
    public function __construct(
        private IsAcceptableMerchandisingCategoryAction $isAcceptableMerchandisingCategory,
    ) {}

    public function execute(int $primaryCategoryId): NormalizedProductCategoryAssignments
    {
        if (! $this->isAcceptableMerchandisingCategory->execute($primaryCategoryId)) {
            throw new InvalidArgumentException('The primary category is not an active merchandising category.');
        }

        $categoryIds = [$primaryCategoryId];
        $current = Category::query()->whereKey($primaryCategoryId)->firstOrFail();
        $guard = 0;

        while ($current->parent_id !== null && $guard < 20) {
            $guard++;
            $parent = Category::withTrashed()->find($current->parent_id);

            if (! $parent instanceof Category) {
                break;
            }

            if (
                ! $parent->trashed()
                && $this->isAcceptableMerchandisingCategory->execute((int) $parent->id)
                && ! in_array((int) $parent->id, $categoryIds, true)
            ) {
                $categoryIds[] = (int) $parent->id;
            }

            $current = $parent;
        }

        return new NormalizedProductCategoryAssignments(
            primaryCategoryId: $primaryCategoryId,
            categoryIds: $categoryIds,
        );
    }
}
