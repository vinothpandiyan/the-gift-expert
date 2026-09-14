<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\ProductCurationEvidence;
use App\Enums\CurationIssueSeverity;
use App\Enums\ProductCurationAuditOutcome;
use App\Enums\ProductCurationPriority;
use App\Models\ProductCurationAudit;

class ResolveProductCurationPriorityAction
{
    public function execute(ProductCurationAudit $audit): ProductCurationPriority
    {
        if ($this->isIntegrityProblem($audit)) {
            return ProductCurationPriority::P0;
        }

        if ($this->isRedundancyProblem($audit)) {
            return ProductCurationPriority::P1;
        }

        if ($this->hasMaterialTaxonomyFinding($audit)) {
            return ProductCurationPriority::P2;
        }

        if ($audit->requires_human_review) {
            return ProductCurationPriority::P3;
        }

        return ProductCurationPriority::P4;
    }

    public function isIntegrityProblem(ProductCurationAudit $audit): bool
    {
        if ($audit->outcome !== ProductCurationAuditOutcome::Completed || $audit->completed_at === null) {
            return true;
        }

        if ($audit->gift_score === null || $audit->catalog_value_score === null) {
            return true;
        }

        if ($this->evidenceIsMalformed($audit)) {
            return true;
        }

        if ($this->isIncompleteDevelopmentProduct($audit)) {
            return true;
        }

        $integrityCodes = (array) config('catalog_curation.human_curation.integrity_issue_codes', []);

        foreach ($this->issues($audit) as $issue) {
            $code = (string) ($issue['code'] ?? '');
            $severity = (string) ($issue['severity'] ?? '');

            if (in_array($code, $integrityCodes, true)) {
                return true;
            }

            if (in_array($severity, [
                CurationIssueSeverity::Critical->value,
                CurationIssueSeverity::Blocking->value,
            ], true) && in_array($code, [
                ...$integrityCodes,
                'missing_commerce_evidence',
            ], true)) {
                return true;
            }
        }

        foreach ($this->taxonomyDifferences($audit) as $difference) {
            if (($difference['cause'] ?? null) === 'missing_current_assignment_evaluation') {
                return true;
            }

            if (($difference['cause'] ?? null) === 'unresolved_taxonomy_label') {
                return true;
            }
        }

        return $this->isInternallyInconsistent($audit);
    }

    public function isRedundancyProblem(ProductCurationAudit $audit): bool
    {
        $peerCount = $this->conceptPeerCount($audit);

        if ($peerCount < 1) {
            return false;
        }

        $lowCatalogValue = $audit->catalog_value_score !== null
            && $audit->catalog_value_score < (int) config('catalog_curation.thresholds.human_review_catalog_value_score', 50);
        $weakDifferentiation = $this->hasWeakDifferentiation($audit);
        $oversaturated = $this->hasIssueCode($audit, 'concept_oversaturated');
        $possibleDuplicate = $this->hasIssueCode($audit, 'possible_concept_duplicate') && $weakDifferentiation;

        return $lowCatalogValue || $weakDifferentiation || $oversaturated || $possibleDuplicate;
    }

    public function hasMaterialTaxonomyFinding(ProductCurationAudit $audit): bool
    {
        $materialCodes = (array) config('catalog_curation.human_curation.material_taxonomy_issue_codes', []);

        foreach ($this->issues($audit) as $issue) {
            $code = (string) ($issue['code'] ?? '');
            $severity = (string) ($issue['severity'] ?? '');
            $forcesReview = ($issue['context']['forces_human_review'] ?? false) === true;

            if (! in_array($code, $materialCodes, true)) {
                continue;
            }

            if ($forcesReview || in_array($severity, [
                CurationIssueSeverity::Material->value,
                CurationIssueSeverity::Critical->value,
                CurationIssueSeverity::Blocking->value,
            ], true)) {
                return true;
            }
        }

        foreach ($this->taxonomyDifferences($audit) as $difference) {
            $severity = (string) ($difference['severity'] ?? '');
            $forcesReview = ($difference['forces_human_review'] ?? false) === true;

            if ($forcesReview || in_array($severity, [
                CurationIssueSeverity::Material->value,
                CurationIssueSeverity::Critical->value,
                CurationIssueSeverity::Blocking->value,
            ], true)) {
                return true;
            }
        }

        return false;
    }

    public function hasEvidenceIssue(ProductCurationAudit $audit): bool
    {
        $codes = (array) config('catalog_curation.human_curation.evidence_issue_codes', []);

        foreach ($this->issues($audit) as $issue) {
            if (in_array((string) ($issue['code'] ?? ''), $codes, true)) {
                return true;
            }
        }

        return $this->evidenceIsMalformed($audit);
    }

    public function taxonomySeverity(ProductCurationAudit $audit): ?string
    {
        $ranks = [
            CurationIssueSeverity::Blocking->value => 6,
            CurationIssueSeverity::Critical->value => 5,
            CurationIssueSeverity::Material->value => 4,
            CurationIssueSeverity::Warning->value => 3,
            CurationIssueSeverity::Advisory->value => 2,
            CurationIssueSeverity::Info->value => 1,
        ];
        $highest = null;
        $highestRank = 0;

        foreach ([...$this->issues($audit), ...$this->taxonomyDifferences($audit)] as $item) {
            $severity = (string) ($item['severity'] ?? '');
            $rank = $ranks[$severity] ?? 0;

            if ($rank > $highestRank) {
                $highestRank = $rank;
                $highest = $severity;
            }
        }

        return $highest;
    }

    public function conceptPeerCount(ProductCurationAudit $audit): int
    {
        $counts = is_array($audit->peer_counts) ? $audit->peer_counts : [];

        return (int) ($counts['concept'] ?? count((array) data_get($audit->peer_product_ids, 'concept', [])));
    }

    private function hasWeakDifferentiation(ProductCurationAudit $audit): bool
    {
        $maxWeak = (int) config('catalog_curation.human_curation.weak_differentiation_max', 6);

        if ($audit->differentiation_factor !== null && $audit->differentiation_factor <= $maxWeak) {
            return true;
        }

        $strength = data_get($audit->catalog_context_snapshot, 'differentiation_strength');

        return in_array($strength, ['weak', 'none'], true);
    }

    private function isIncompleteDevelopmentProduct(ProductCurationAudit $audit): bool
    {
        if ($audit->gift_score !== 0) {
            return false;
        }

        return $this->hasIssueCode($audit, 'missing_commerce_evidence')
            || $this->hasIssueCode($audit, 'missing_primary_category')
            || $this->hasIssueCode($audit, 'weak_product_confidence');
    }

    private function evidenceIsMalformed(ProductCurationAudit $audit): bool
    {
        $snapshot = $audit->evidence_snapshot;

        if (! is_array($snapshot) || $snapshot === []) {
            return true;
        }

        if (! isset($snapshot['product_id']) || ! isset($snapshot['name'])) {
            return true;
        }

        if ((int) $snapshot['product_id'] !== (int) $audit->product_id) {
            return true;
        }

        if (trim((string) $snapshot['name']) === '') {
            return true;
        }

        try {
            ProductCurationEvidence::fromArray($snapshot);
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    private function isInternallyInconsistent(ProductCurationAudit $audit): bool
    {
        if ($audit->recommendation === null && $audit->requires_human_review === false && $audit->gift_score !== null) {
            return true;
        }

        $semantic = is_array($audit->semantic_evaluation) ? $audit->semantic_evaluation : [];

        if ($semantic === [] && $audit->outcome === ProductCurationAuditOutcome::Completed) {
            return $audit->gift_score_components === [] || $audit->gift_score_components === null;
        }

        return false;
    }

    private function hasIssueCode(ProductCurationAudit $audit, string $code): bool
    {
        return collect($this->issues($audit))->contains(
            fn (array $issue): bool => ($issue['code'] ?? null) === $code,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function issues(ProductCurationAudit $audit): array
    {
        if (! is_array($audit->issues)) {
            return [];
        }

        return array_values(array_filter(
            $audit->issues,
            fn (mixed $issue): bool => is_array($issue),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taxonomyDifferences(ProductCurationAudit $audit): array
    {
        if (! is_array($audit->taxonomy_differences)) {
            return [];
        }

        return array_values(array_filter(
            $audit->taxonomy_differences,
            fn (mixed $difference): bool => is_array($difference),
        ));
    }
}
