<?php

namespace App\CuratedCatalog;

use App\Enums\TaxonomyClassificationStatus;
use App\Models\Product;

readonly class ClassifyCuratedMerchantProductResult
{
    /**
     * @param  list<string>  $warnings
     * @param  list<string>  $reviewReasons
     */
    public function __construct(
        public Product $product,
        public TaxonomyClassificationStatus $status,
        public bool $classified,
        public string $reason,
        public array $warnings,
        public array $reviewReasons,
        public ?CuratedClassificationProposal $proposal,
    ) {}
}
