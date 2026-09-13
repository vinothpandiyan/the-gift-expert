<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\ProductCurationEvidence;
use App\Enums\CurationIssueCode;
use App\Enums\CurationIssueSeverity;

class BuildCurationIssuesAction
{
    /**
     * @param  list<array<string, mixed>>  $semanticIssues
     * @param  list<array<string, mixed>>  $differences
     * @param  array<string, array<string, mixed>>  $giftComponents
     * @param  array<string, mixed>  $semantic
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    public function execute(
        ProductCurationEvidence $evidence,
        array $semanticIssues,
        array $differences,
        int $giftScore,
        int $catalogValueScore,
        string $confidence,
        array $giftComponents,
        array $semantic,
        array $context,
    ): array {
        $issues = $semanticIssues;
        $missingCommerce = [];

        if ($evidence->priceAmount === null) {
            $missingCommerce[] = 'price';
        }

        if (! collect($evidence->images)->contains(fn (array $image): bool => $image['is_primary'] === true)) {
            $missingCommerce[] = 'primary_image';
        }

        if (! collect($evidence->offers)->contains(fn (array $offer): bool => $offer['status'] === 'active')) {
            $missingCommerce[] = 'active_offer';
        }

        if ($evidence->provenance === []) {
            $missingCommerce[] = 'provenance';
        }

        $valueForMoney = $giftComponents['value_for_money']['score'] ?? null;

        if ($valueForMoney === null) {
            $missingCommerce[] = 'value_for_money_assessment';
        }

        if ($missingCommerce !== []) {
            $issues[] = $this->issue(
                CurationIssueCode::MissingCommerceEvidence,
                'Material commerce evidence is missing.',
                CurationIssueSeverity::Warning,
                ['missing' => $missingCommerce],
            );
        }

        if ($giftScore < (int) config('catalog_curation.thresholds.human_review_gift_score', 65)) {
            $semanticScore = collect($giftComponents)
                ->except('product_vendor_confidence')
                ->sum(fn (array $component): int => (int) ($component['score'] ?? 0));
            $semanticKnownMaximum = collect($giftComponents)
                ->except('product_vendor_confidence')
                ->filter(fn (array $component): bool => $component['score'] !== null)
                ->sum(fn (array $component): int => (int) $component['max']);

            $issues[] = $this->issue(
                CurationIssueCode::WeakGiftFit,
                'Gift fit scored below the human-review baseline.',
                CurationIssueSeverity::Warning,
                [
                    'gift_score' => $giftScore,
                    'semantic_score' => $semanticScore,
                    'semantic_known_maximum' => $semanticKnownMaximum,
                    'substantive' => $semanticKnownMaximum > 0
                        && ($semanticScore / $semanticKnownMaximum) < 0.45,
                ],
            );
        }

        if ($catalogValueScore < (int) config('catalog_curation.thresholds.human_review_catalog_value_score', 50)) {
            $issues[] = $this->issue(
                CurationIssueCode::LowCatalogValue,
                'Catalog value scored below the human-review baseline.',
                CurationIssueSeverity::Warning,
            );
        }

        if ($confidence === 'low') {
            $issues[] = $this->issue(CurationIssueCode::LowAiConfidence, 'Semantic confidence requires review.');
        }

        if ($valueForMoney !== null && $valueForMoney < (int) config('catalog_curation.thresholds.weak_value_for_money', 8)) {
            $issues[] = $this->issue(
                CurationIssueCode::WeakValueForMoney,
                'Value for money is weak.',
                CurationIssueSeverity::Warning,
                ['score' => $valueForMoney, 'evidence_status' => $giftComponents['value_for_money']['evidence_status'] ?? 'supported'],
            );
        }

        $productConfidence = (int) ($giftComponents['product_vendor_confidence']['score'] ?? 0);

        if ($productConfidence < (int) config('catalog_curation.thresholds.weak_product_vendor_confidence', 6)) {
            $issues[] = $this->issue(
                CurationIssueCode::WeakProductConfidence,
                'Factual product/vendor confidence is weak.',
                CurationIssueSeverity::Warning,
                ['score' => $productConfidence],
            );
        }

        if (! collect($evidence->taxonomy['categories'] ?? [])->contains(fn (array $category): bool => ($category['is_primary'] ?? false) === true)) {
            $issues[] = $this->issue(CurationIssueCode::MissingPrimaryCategory, 'Product has no primary category.');
        }

        if ($this->whyIsWeak((string) ($semantic['why_this_gift'] ?? ''))) {
            $issues[] = $this->issue(CurationIssueCode::WeakWhyThisGift, 'Why-this-gift lacks a meaningful gifting scenario.');
        }

        foreach ($differences as $difference) {
            $code = $this->taxonomyIssueCode($difference);

            if ($this->hasTaxonomyIssue($issues, $code, $difference)) {
                continue;
            }

            $issues[] = $this->issue(
                $code,
                (string) ($difference['reason'] ?? 'Current taxonomy differs from the audit fit.'),
                CurationIssueSeverity::from((string) ($difference['severity'] ?? 'advisory')),
                $difference,
            );
        }

        if (($context['snapshot']['signals']['concept_oversaturated'] ?? false) === true) {
            $differentiationUnclear = (int) ($giftComponents['uniqueness']['score'] ?? 0) < 8
                && ($semantic['differentiation_signals'] ?? []) === [];

            $issues[] = $this->issue(
                CurationIssueCode::ConceptOversaturated,
                'The normalized concept is oversaturated.',
                CurationIssueSeverity::Warning,
                [
                    'peer_count' => $context['peer_counts']['concept'] ?? 0,
                    'differentiation_unclear' => $differentiationUnclear,
                ],
            );
        }

        if (($context['snapshot']['signals']['possible_concept_duplicate'] ?? false) === true) {
            $issues[] = $this->issue(
                CurationIssueCode::PossibleConceptDuplicate,
                'Another Product shares this normalized concept.',
                CurationIssueSeverity::Info,
                ['peer_product_ids' => $context['peer_product_ids']['concept'] ?? []],
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function issue(
        CurationIssueCode $code,
        string $message,
        CurationIssueSeverity $severity = CurationIssueSeverity::Warning,
        array $context = [],
    ): array {
        return [
            'code' => $code->value,
            'severity' => $severity->value,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>  $difference
     */
    private function taxonomyIssueCode(array $difference): CurationIssueCode
    {
        return match ($difference['cause'] ?? null) {
            'hard_applicability_conflict' => CurationIssueCode::HardTaxonomyConflict,
            'missing_current_assignment_evaluation' => CurationIssueCode::MissingCurrentAssignmentEvaluation,
            default => ($difference['forces_human_review'] ?? false) === true
                ? match ($difference['dimension'] ?? null) {
                    'relationships' => CurationIssueCode::RelationshipOverclassification,
                    'occasions' => CurationIssueCode::OccasionOverclassification,
                    'interests' => CurationIssueCode::InterestMismatch,
                    'gift_types' => CurationIssueCode::GiftTypeMismatch,
                    default => CurationIssueCode::TaxonomyConflict,
                }
            : CurationIssueCode::TaxonomyFitAdvisory,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  array<string, mixed>  $difference
     */
    private function hasTaxonomyIssue(array $issues, CurationIssueCode $code, array $difference): bool
    {
        return collect($issues)->contains(function (array $issue) use ($code, $difference): bool {
            if (($issue['code'] ?? null) !== $code->value) {
                return false;
            }

            return ($issue['context']['dimension'] ?? null) === ($difference['dimension'] ?? null)
                && (int) ($issue['context']['taxonomy']['id'] ?? 0) === (int) ($difference['taxonomy']['id'] ?? 0);
        });
    }

    private function whyIsWeak(string $why): bool
    {
        $normalized = mb_strtolower(trim($why));

        if (mb_strlen($normalized) < 40) {
            return true;
        }

        foreach ((array) config('catalog_curation.weak_why_phrases', []) as $phrase) {
            if ($normalized === $phrase || str_starts_with($normalized, $phrase.'.')) {
                return true;
            }
        }

        return preg_match('/\b(for|when|because|who|during|celebrat(?:e|ion|ing))\b/u', $normalized) !== 1;
    }
}
