<?php

namespace App\Actions\CuratedCatalog;

use App\Enums\ProductStatus;
use App\Models\CuratedProductIntakeItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class QueryProductsForCuratedClassificationAction
{
    /**
     * @return Collection<int, Product>
     */
    public function execute(
        ?int $intakeRunId = null,
        ?int $productId = null,
        ?string $merchantSlug = null,
        ?string $status = null,
        ?int $limit = null,
    ): Collection {
        $query = Product::query()
            ->with([
                'affiliateLinks.merchant:id,slug,name',
                'affiliateLinks.catalogProductSources.sourceList:id,merchant_id,kind,relationship_id,is_active,name',
                'categories',
            ])
            ->whereHas('affiliateLinks')
            ->orderBy('id');

        if ($intakeRunId !== null) {
            $productIds = CuratedProductIntakeItem::query()
                ->where('curated_product_intake_run_id', $intakeRunId)
                ->whereNotNull('product_id')
                ->distinct()
                ->pluck('product_id');

            $query->whereIn('id', $productIds);
        } else {
            $query->where('status', ProductStatus::Draft);
        }

        if ($productId !== null) {
            $query->whereKey($productId);
        }

        if (is_string($status) && $status !== '') {
            $query->where('taxonomy_classification_status', $status);
        }

        if (is_string($merchantSlug) && $merchantSlug !== '') {
            $query->whereHas('affiliateLinks.merchant', function (Builder $merchantQuery) use ($merchantSlug): void {
                $merchantQuery->where('slug', $merchantSlug);
            });
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }
}
