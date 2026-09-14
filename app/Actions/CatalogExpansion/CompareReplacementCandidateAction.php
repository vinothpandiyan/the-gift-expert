<?php

namespace App\Actions\CatalogExpansion;

use App\Enums\ProductCurationDecision;
use App\Enums\ProductStatus;
use App\Enums\ReplacementAdvice;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;

class CompareReplacementCandidateAction
{
    /**
     * @return array{
     *     advice: ReplacementAdvice,
     *     incoming: array<string, mixed>,
     *     existing: array<string, mixed>|null,
     *     archived: bool
     * }
     */
    public function execute(
        Product $incoming,
        ProductCurationAudit $incomingAudit,
        Product $existing,
        ProductCurationAudit $existingAudit,
    ): array {
        $sameConcept = is_string($incomingAudit->concept_key)
            && $incomingAudit->concept_key !== ''
            && $incomingAudit->concept_key === $existingAudit->concept_key;

        $existingIsRemove = ProductCurationDecisionRecord::query()
            ->current()
            ->where('product_id', $existing->id)
            ->where('decision', ProductCurationDecision::RemoveCandidate)
            ->exists();

        $advice = ReplacementAdvice::NotAReplacement;

        if ($sameConcept && $existingIsRemove) {
            $incomingGift = (int) $incomingAudit->gift_score;
            $existingGift = (int) $existingAudit->gift_score;
            $incomingValue = (int) $incomingAudit->catalog_value_score;
            $existingValue = (int) $existingAudit->catalog_value_score;
            $betterGift = $incomingGift > $existingGift;
            $betterValue = $incomingValue > $existingValue;

            $advice = match (true) {
                $betterGift && $betterValue => ReplacementAdvice::ReplacementRecommended,
                $betterGift || $betterValue => ReplacementAdvice::ReplacementUncertain,
                default => ReplacementAdvice::ReplacementUncertain,
            };
        }

        return [
            'advice' => $advice,
            'incoming' => $this->row($incoming, $incomingAudit),
            'existing' => $this->row($existing, $existingAudit),
            'archived' => $existing->status === ProductStatus::Archived,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Product $product, ProductCurationAudit $audit): array
    {
        return [
            'product_id' => (int) $product->id,
            'title' => (string) $product->name,
            'concept' => $audit->concept_key,
            'budget' => is_array($audit->catalog_context_snapshot)
                ? ($audit->catalog_context_snapshot['price_band'] ?? $product->price_amount)
                : $product->price_amount,
            'gift_score' => $audit->gift_score,
            'catalog_value' => $audit->catalog_value_score,
            'gift_intent' => $audit->gift_intents,
            'differentiation' => data_get($audit->catalog_context_snapshot, 'relative_coverage.differentiation'),
            'evidence_quality' => $audit->ai_confidence?->value ?? $audit->ai_confidence,
        ];
    }
}
