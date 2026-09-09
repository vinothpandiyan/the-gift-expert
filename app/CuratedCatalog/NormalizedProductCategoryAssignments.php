<?php

namespace App\CuratedCatalog;

readonly class NormalizedProductCategoryAssignments
{
    /**
     * @param  list<int>  $categoryIds
     */
    public function __construct(
        public int $primaryCategoryId,
        public array $categoryIds,
    ) {}
}
