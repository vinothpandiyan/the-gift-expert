<?php

namespace App\Jobs;

use App\Actions\CuratedCatalog\ClassifyCuratedMerchantProductAction;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ClassifyCuratedMerchantProductJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 180;

    public function __construct(
        public int $productId,
        public bool $force = false,
        public bool $retryFailed = false,
    ) {}

    public function uniqueId(): string
    {
        return 'classify-curated-merchant-product:'.$this->productId;
    }

    public function handle(ClassifyCuratedMerchantProductAction $classify): void
    {
        $product = Product::query()
            ->with([
                'affiliateLinks.merchant:id,slug,name',
                'affiliateLinks.catalogProductSources.sourceList',
                'categories',
            ])
            ->find($this->productId);

        if ($product === null) {
            return;
        }

        $classify->execute($product, $this->force, $this->retryFailed);
    }
}
