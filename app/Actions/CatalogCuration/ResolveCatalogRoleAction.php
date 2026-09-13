<?php

namespace App\Actions\CatalogCuration;

use App\Enums\CatalogRole;

class ResolveCatalogRoleAction
{
    /**
     * @param  array<string, mixed>  $semantic
     * @param  array<string, mixed>  $giftComponents
     * @param  array<string, mixed>  $context
     */
    public function execute(
        int $giftScore,
        int $catalogValueScore,
        array $semantic,
        array $giftComponents,
        array $context,
    ): CatalogRole {
        $priceBand = $context['snapshot']['price_band'] ?? null;
        $niche = ($semantic['niche_signals'] ?? []) !== [];
        $uniqueness = (int) ($giftComponents['uniqueness']['score'] ?? 0);
        $value = $giftComponents['value_for_money']['score'] ?? null;

        return match (true) {
            $giftScore >= 80 && $catalogValueScore >= 70 => CatalogRole::BestOverall,
            is_int($value) && $value >= 10 && $catalogValueScore >= 60 => CatalogRole::BestValue,
            $priceBand === '5000-plus' && $giftScore >= 65 => CatalogRole::PremiumPick,
            $niche && $catalogValueScore >= 70 => CatalogRole::NichePick,
            $uniqueness >= 10 && $catalogValueScore >= 60 => CatalogRole::UniquePick,
            default => CatalogRole::Undifferentiated,
        };
    }
}
