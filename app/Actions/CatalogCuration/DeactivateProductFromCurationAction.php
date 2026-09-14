<?php

namespace App\Actions\CatalogCuration;

use App\Enums\ProductCurationDecision as ProductCurationDecisionValue;
use App\Enums\ProductCurationRemediationStatus;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductCurationDecision;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DeactivateProductFromCurationAction
{
    public function execute(ProductCurationDecision $decision): ProductCurationDecision
    {
        if ($decision->decision !== ProductCurationDecisionValue::Deactivate) {
            throw ValidationException::withMessages([
                'decision' => ['Only an explicit DEACTIVATE human decision can archive a gift through curation remediation.'],
            ]);
        }

        if (! $decision->isCurrent()) {
            throw ValidationException::withMessages([
                'decision' => ['Only the current human curation decision can deactivate a gift.'],
            ]);
        }

        $product = Product::query()->whereKey($decision->product_id)->lockForUpdate()->first();

        if (! $product instanceof Product) {
            throw ValidationException::withMessages([
                'product' => ['The gift could not be locked for deactivation.'],
            ]);
        }

        if ($product->status !== ProductStatus::Archived) {
            $product->update([
                'status' => ProductStatus::Archived,
            ]);
        }

        $decision->remediation_status = ProductCurationRemediationStatus::Completed;
        $decision->save();

        Log::info('catalog_curation.product_deactivated', [
            'decision_id' => $decision->id,
            'product_id' => $product->id,
            'source_audit_run_id' => $decision->source_audit_run_id,
            'decided_by_user_id' => $decision->decided_by_user_id,
        ]);

        return $decision->fresh() ?? $decision;
    }
}
