<?php

namespace App\Actions\CatalogCuration;

use App\Enums\CurationRecommendation;

class RecommendProductCurationAction
{
    /**
     * @param  list<array<string, mixed>>  $issues
     */
    public function execute(
        int $giftScore,
        int $catalogValueScore,
        array $issues,
        bool $hasNicheEvidence,
    ): CurationRecommendation {
        $codes = array_column($issues, 'code');
        $materialBlockers = [
            'low_ai_confidence',
            // Legacy completed audits used this ungraded code.
            'taxonomy_conflict',
            'missing_primary_category',
            'weak_why_this_gift',
            'weak_product_confidence',
            'missing_commerce_evidence',
            'weak_value_for_money',
        ];
        $hasTaxonomyReviewFinding = collect($issues)->contains(
            fn (array $issue): bool => ($issue['context']['forces_human_review'] ?? false) === true,
        );

        $weakGiftIssue = collect($issues)->first(
            fn (array $issue): bool => ($issue['code'] ?? null) === 'weak_gift_fit',
        );
        $substantiveWeakGift = ($weakGiftIssue['context']['substantive'] ?? false) === true;

        if (array_intersect($codes, $materialBlockers) !== [] || $hasTaxonomyReviewFinding) {
            return CurationRecommendation::Review;
        }

        if ($giftScore < (int) config('catalog_curation.thresholds.remove_gift_score', 45)
            && $catalogValueScore < (int) config('catalog_curation.thresholds.remove_catalog_value_score', 35)
            && $substantiveWeakGift
            && in_array('low_catalog_value', $codes, true)) {
            return CurationRecommendation::RemoveCandidate;
        }

        if ($giftScore >= (int) config('catalog_curation.thresholds.replace_gift_score', 55)
            && $catalogValueScore < (int) config('catalog_curation.thresholds.replace_catalog_value_score', 50)
            && in_array('concept_oversaturated', $codes, true)) {
            return CurationRecommendation::ReplaceCandidate;
        }

        if ($giftScore >= (int) config('catalog_curation.thresholds.feature_gift_score', 80)
            && $catalogValueScore >= (int) config('catalog_curation.thresholds.feature_catalog_value_score', 70)) {
            return CurationRecommendation::Feature;
        }

        if ($giftScore >= (int) config('catalog_curation.thresholds.keep_gift_score', 65)
            && $catalogValueScore >= (int) config('catalog_curation.thresholds.keep_catalog_value_score', 60)) {
            return CurationRecommendation::Keep;
        }

        if ($giftScore >= (int) config('catalog_curation.thresholds.keep_niche_gift_score', 55)
            && $catalogValueScore >= (int) config('catalog_curation.thresholds.keep_niche_catalog_value_score', 70)
            && $hasNicheEvidence) {
            return CurationRecommendation::KeepNiche;
        }

        return CurationRecommendation::Review;
    }
}
