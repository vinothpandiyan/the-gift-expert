<?php

namespace App\Actions\CatalogCuration;

use App\Enums\CurationRecommendation;

class AmendCurationReviewAction
{
    private const REVIEW_CODES = [
        'low_ai_confidence',
        // Legacy completed audits used this ungraded code.
        'taxonomy_conflict',
        'missing_primary_category',
        'weak_why_this_gift',
        'weak_value_for_money',
        'weak_product_confidence',
        'missing_commerce_evidence',
    ];

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array{recommendation: CurationRecommendation, requires_human_review: bool}
     */
    public function execute(
        CurationRecommendation $recommendation,
        array $issues,
        int $giftScore,
        int $catalogValueScore,
    ): array {
        $codes = array_values(array_unique(array_column($issues, 'code')));
        $hasUnknownEvidence = in_array('missing_commerce_evidence', $codes, true)
            || collect($issues)->contains(
                fn (array $issue): bool => ($issue['code'] ?? null) === 'weak_value_for_money'
                    && ($issue['context']['evidence_status'] ?? null) === 'unknown',
            );
        $requiresReview = $giftScore < (int) config('catalog_curation.thresholds.human_review_gift_score', 65)
            || $catalogValueScore < (int) config('catalog_curation.thresholds.human_review_catalog_value_score', 50)
            || array_intersect($codes, self::REVIEW_CODES) !== []
            || collect($issues)->contains(
                fn (array $issue): bool => ($issue['context']['forces_human_review'] ?? false) === true,
            )
            || collect($issues)->contains(
                fn (array $issue): bool => ($issue['code'] ?? null) === 'concept_oversaturated'
                    && ($issue['context']['differentiation_unclear'] ?? false) === true,
            )
            || $recommendation->isCandidate();
        $hasSubstantiveCandidateEvidence = match ($recommendation) {
            CurationRecommendation::RemoveCandidate => in_array('weak_gift_fit', $codes, true)
                && in_array('low_catalog_value', $codes, true),
            CurationRecommendation::ReplaceCandidate => in_array('concept_oversaturated', $codes, true),
            default => true,
        };

        if ($recommendation->isCandidate() && ($hasUnknownEvidence || ! $hasSubstantiveCandidateEvidence)) {
            $recommendation = CurationRecommendation::Review;
            $requiresReview = true;
        }

        return [
            'recommendation' => $recommendation,
            'requires_human_review' => $requiresReview || $recommendation === CurationRecommendation::Review,
        ];
    }
}
