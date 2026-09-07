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

        $amount = (string) $priceAmount;

        $ranges = BudgetRange::query()
            ->where('is_active', true)
            ->where('currency', $currency)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($ranges as $range) {
            if ($range->containsAmount($amount)) {
                return $range;
            }
        }

        return null;
    }
}
