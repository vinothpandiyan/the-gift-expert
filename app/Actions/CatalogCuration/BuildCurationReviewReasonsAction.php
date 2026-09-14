<?php

namespace App\Actions\CatalogCuration;

use App\Enums\CurationIssueSeverity;
use App\Models\ProductCurationAudit;
use Illuminate\Support\Str;

class BuildCurationReviewReasonsAction
{
    public function __construct(
        private ResolveProductCurationPriorityAction $priority,
    ) {}

    /**
     * @return list<string>
     */
    public function execute(ProductCurationAudit $audit): array
    {
        $reasons = [];
        $peerCount = $this->priority->conceptPeerCount($audit);
        $conceptSize = $peerCount + 1;
        $conceptLabel = $audit->concept_label ?: $audit->concept_key;

        if ($this->priority->isIntegrityProblem($audit)) {
            $reasons[] = 'Audit evidence or taxonomy resolution is malformed or internally inconsistent.';
        }

        if ($audit->catalog_value_score !== null
            && $audit->catalog_value_score < (int) config('catalog_curation.thresholds.human_review_catalog_value_score', 50)) {
            $reasons[] = 'Catalog Value is low: '.$audit->catalog_value_score;
        }

        if ($audit->gift_score !== null
            && $audit->gift_score < (int) config('catalog_curation.thresholds.human_review_gift_score', 65)) {
            $reasons[] = 'Gift Score is below the human-review baseline: '.$audit->gift_score;
        }

        if ($conceptSize > 1 && filled($conceptLabel)) {
            $reasons[] = $conceptSize.' Products share the '.$conceptLabel.' concept';
        }

        if ($peerCount >= 1 && $this->hasWeakDifferentiation($audit)) {
            $reasons[] = 'Product has limited differentiation from peers';
        }

        foreach ($this->taxonomyReasons($audit) as $reason) {
            $reasons[] = $reason;
        }

        foreach ($this->issueReasons($audit) as $reason) {
            $reasons[] = $reason;
        }

        if ($audit->ai_confidence?->value === 'low') {
            $reasons[] = 'AI confidence is low.';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @return list<string>
     */
    private function taxonomyReasons(ProductCurationAudit $audit): array
    {
        $reasons = [];

        foreach ((array) $audit->taxonomy_differences as $difference) {
            if (! is_array($difference)) {
                continue;
            }

            $severity = (string) ($difference['severity'] ?? '');
            $forcesReview = ($difference['forces_human_review'] ?? false) === true;

            if (! $forcesReview && ! in_array($severity, [
                CurationIssueSeverity::Material->value,
                CurationIssueSeverity::Critical->value,
                CurationIssueSeverity::Blocking->value,
            ], true)) {
                continue;
            }

            $dimension = Str::of((string) ($difference['dimension'] ?? 'taxonomy'))->replace('_', ' ')->headline();
            $name = (string) data_get($difference, 'taxonomy.name', '');
            $reason = (string) ($difference['reason'] ?? '');

            if ($name !== '') {
                $reasons[] = 'Current '.$dimension.' "'.$name.'" is materially inconsistent with the evaluator\'s product fit assessment.'
                    .($reason !== '' ? ' '.$reason : '');

                continue;
            }

            if ($reason !== '') {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function issueReasons(ProductCurationAudit $audit): array
    {
        $skip = [
            'low_catalog_value',
            'weak_gift_fit',
            'low_ai_confidence',
            'concept_oversaturated',
            'possible_concept_duplicate',
            'taxonomy_fit_advisory',
        ];
        $reasons = [];

        foreach ((array) $audit->issues as $issue) {
            if (! is_array($issue) || in_array((string) ($issue['code'] ?? ''), $skip, true)) {
                continue;
            }

            $message = trim((string) ($issue['message'] ?? ''));

            if ($message !== '') {
                $reasons[] = $message;
            }
        }

        if ($this->priority->hasEvidenceIssue($audit) && ! collect($reasons)->contains(
            fn (string $reason): bool => str_contains(strtolower($reason), 'evidence') || str_contains(strtolower($reason), 'commerce'),
        )) {
            $reasons[] = 'Commerce or evidence completeness needs review.';
        }

        return $reasons;
    }

    private function hasWeakDifferentiation(ProductCurationAudit $audit): bool
    {
        $maxWeak = (int) config('catalog_curation.human_curation.weak_differentiation_max', 6);

        if ($audit->differentiation_factor !== null && $audit->differentiation_factor <= $maxWeak) {
            return true;
        }

        return in_array(data_get($audit->catalog_context_snapshot, 'differentiation_strength'), ['weak', 'none'], true);
    }
}
