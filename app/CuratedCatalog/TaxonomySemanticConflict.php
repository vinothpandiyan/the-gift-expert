<?php

namespace App\CuratedCatalog;

use App\Enums\TaxonomyDimension;

readonly class TaxonomySemanticConflict
{
    public function __construct(
        public TaxonomyDimension $leftDimension,
        public int $leftId,
        public TaxonomyDimension $rightDimension,
        public int $rightId,
    ) {}
}
