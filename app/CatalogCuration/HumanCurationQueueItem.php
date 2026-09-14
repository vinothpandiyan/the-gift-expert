<?php

namespace App\CatalogCuration;

use App\Enums\P3QualitySubgroup;
use App\Enums\ProductCurationPriority;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationDecision;

readonly class HumanCurationQueueItem
{
    public function __construct(
        public Product $product,
        public ProductCurationAudit $audit,
        public ProductCurationPriority $priority,
        public ?ProductCurationDecision $decision,
        public ?P3QualitySubgroup $qualitySubgroup = null,
    ) {}
}
