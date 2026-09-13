<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\ProductCurationEvidence;
use Illuminate\Support\Carbon;

class CalculateGiftScoreAction
{
    /**
     * @param  array<string, mixed>  $semantic
     * @return array{score: int, components: array<string, array{score: int|null, max: int, evidence_status: string, source: string}>}
     */
    public function execute(ProductCurationEvidence $evidence, array $semantic): array
    {
        $semanticComponents = $semantic['gift_components'] ?? [];
        $components = [];

        foreach ((array) config('catalog_curation.gift_score.semantic_components', []) as $key => $maximum) {
            $hasValue = array_key_exists($key, $semanticComponents);
            $value = $semanticComponents[$key] ?? null;

            if ($key === 'value_for_money' && $hasValue && $value === null) {
                $components[$key] = [
                    'score' => null,
                    'max' => (int) $maximum,
                    'evidence_status' => 'unknown',
                    'source' => 'ai_semantic',
                ];

                continue;
            }

            if (! $hasValue || ! is_int($value) || $value < 0 || $value > (int) $maximum) {
                throw new \InvalidArgumentException("Invalid semantic gift component [{$key}].");
            }

            $components[$key] = [
                'score' => $value,
                'max' => (int) $maximum,
                'evidence_status' => 'supported',
                'source' => 'ai_semantic',
            ];
        }

        $facts = (array) config('catalog_curation.gift_score.product_vendor_confidence', []);
        $activeOffers = collect($evidence->offers)->where('status', 'active');
        $confidenceScore = 0;
        $confidenceScore += $activeOffers->isNotEmpty() ? (int) $facts['active_offer'] : 0;
        $confidenceScore += collect($evidence->images)
            ->contains(fn (array $image): bool => $image['is_primary'] === true)
                ? (int) $facts['primary_image']
                : 0;
        $confidenceScore += $evidence->provenance !== [] ? (int) $facts['provenance'] : 0;
        $recentCutoff = now()->subDays((int) config('catalog_curation.thresholds.stale_last_seen_days', 60));
        $hasRecentOffer = $activeOffers->contains(function (array $offer) use ($recentCutoff): bool {
            $lastSeen = $offer['last_seen_at'] ?? null;

            return is_string($lastSeen) && Carbon::parse($lastSeen)->greaterThanOrEqualTo($recentCutoff);
        });
        $confidenceScore += $hasRecentOffer ? (int) $facts['recent_last_seen'] : 0;
        $confidenceMaximum = (int) config('catalog_curation.gift_score.database_components.product_vendor_confidence', 10);
        $components['product_vendor_confidence'] = [
            'score' => $confidenceScore,
            'max' => $confidenceMaximum,
            'evidence_status' => match (true) {
                $confidenceScore === $confidenceMaximum => 'supported',
                $confidenceScore === 0 => 'missing',
                default => 'partial',
            },
            'source' => 'database',
        ];

        return [
            'score' => array_sum(array_map(
                fn (array $component): int => $component['score'] ?? 0,
                $components,
            )),
            'components' => $components,
        ];
    }
}
