<?php

namespace App\Actions\Product;

use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use Illuminate\Support\Facades\DB;

class BackfillLegacyPublishedProductTaxonomyClassificationAction
{
    public function execute(): int
    {
        $ids = DB::table('products')
            ->join('category_product', 'category_product.product_id', '=', 'products.id')
            ->where('products.status', ProductStatus::Published->value)
            ->where('products.taxonomy_classification_status', TaxonomyClassificationStatus::None->value)
            ->whereNull('products.deleted_at')
            ->where('category_product.is_primary', true)
            ->distinct()
            ->pluck('products.id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table('products')
            ->whereIn('id', $ids)
            ->update([
                'taxonomy_classification_status' => TaxonomyClassificationStatus::HumanApproved->value,
                'taxonomy_approved_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
