<?php

namespace App\Actions\CatalogCandidate;

use App\Models\BudgetRange;

class MapPriceToBudgetRangeAction
{
    public function execute(?string $priceAmount, ?string $priceCurrency): ?BudgetRange
    {
        if ($priceAmount === null || $priceCurrency === null || ! is_numeric($priceAmount)) {
            return null;
        }

        $currency = strtoupper(trim($priceCurrency));

        if ($currency === '') {
            return null;
        }

        $amount = (float) $priceAmount;

        $ranges = BudgetRange::query()
            ->where('is_active', true)
            ->where('currency', $currency)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($ranges as $range) {
            if ($range->min_amount !== null && $amount < (float) $range->min_amount) {
                continue;
            }

            if ($range->max_amount !== null && $amount > (float) $range->max_amount) {
                continue;
            }

            return $range;
        }

        return null;
    }
}
