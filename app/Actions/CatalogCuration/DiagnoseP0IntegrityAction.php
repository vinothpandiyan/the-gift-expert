<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\P0IntegrityDiagnosisResult;
use App\Enums\P0IntegrityDiagnosis;
use App\Models\Product;
use App\Models\ProductCurationAudit;

class DiagnoseP0IntegrityAction
{
    /**
     * Inspect current catalog data and persisted audit evidence only.
     * Does not call the semantic evaluator.
     */
    public function execute(Product $product, ProductCurationAudit $audit): P0IntegrityDiagnosisResult
    {
        $product->loadMissing([
            'categories',
            'relationships',
            'occasions',
            'interests',
            'giftTypes',
            'affiliateLinks',
            'images',
        ]);

        $signals = [
            ...$this->catalogDefectSignals($product, $audit),
            ...$this->auditAnomalySignals($product, $audit),
        ];

        $hasCatalogDefect = $this->hasSignalPrefix($signals, 'catalog:');
        $hasAuditAnomaly = $this->hasSignalPrefix($signals, 'audit:');

        if ($hasCatalogDefect && ! $this->currentProductIsUsable($product)) {
            return new P0IntegrityDiagnosisResult(
                diagnosis: P0IntegrityDiagnosis::CatalogDefect,
                summary: 'The current Product record is incomplete, unusable, or looks like development inventory.',
                signals: $signals,
            );
        }

        if ($hasAuditAnomaly && $this->currentProductIsUsable($product) && ! $this->looksLikeDevelopmentPlaceholder($product)) {
            return new P0IntegrityDiagnosisResult(
                diagnosis: P0IntegrityDiagnosis::AuditAnomaly,
                summary: 'The accepted audit evidence is malformed or unresolved, but the current Product data does not show an underlying catalog defect.',
                signals: $signals,
            );
        }

        if ($hasCatalogDefect) {
            return new P0IntegrityDiagnosisResult(
                diagnosis: P0IntegrityDiagnosis::CatalogDefect,
                summary: 'The current Product record has integrity problems that are visible outside the historical audit.',
                signals: $signals,
            );
        }

        return new P0IntegrityDiagnosisResult(
            diagnosis: P0IntegrityDiagnosis::Uncertain,
            summary: 'The P0 trigger cannot be classified confidently from current Product data and persisted audit evidence.',
            signals: $signals,
        );
    }

    /**
     * @return list<string>
     */
    private function catalogDefectSignals(Product $product, ProductCurationAudit $audit): array
    {
        $signals = [];

        if ($this->looksLikeDevelopmentPlaceholder($product)) {
            $signals[] = 'catalog:development_or_test_placeholder';
        }

        if (trim((string) $product->name) === '') {
            $signals[] = 'catalog:missing_name';
        }

        if ($product->price_amount === null || (float) $product->price_amount <= 0) {
            $signals[] = 'catalog:missing_price';
        }

        if ($product->affiliateLinks->isEmpty()) {
            $signals[] = 'catalog:missing_offer';
        }

        if ($product->categories->isEmpty()) {
            $signals[] = 'catalog:missing_category';
        }

        if ($audit->gift_score === 0 && (
            $this->hasIssueCode($audit, 'missing_commerce_evidence')
            || $this->hasIssueCode($audit, 'missing_primary_category')
            || $this->hasIssueCode($audit, 'weak_product_confidence')
        )) {
            $signals[] = 'catalog:incomplete_development_product';
        }

        return $signals;
    }

    /**
     * @return list<string>
     */
    private function auditAnomalySignals(Product $product, ProductCurationAudit $audit): array
    {
        $signals = [];
        $integrityCodes = (array) config('catalog_curation.human_curation.integrity_issue_codes', []);

        foreach ($this->issues($audit) as $issue) {
            $code = (string) ($issue['code'] ?? '');

            if (in_array($code, $integrityCodes, true)) {
                $signals[] = 'audit:issue:'.$code;
            }
        }

        foreach ($this->taxonomyDifferences($audit) as $difference) {
            $cause = (string) ($difference['cause'] ?? '');

            if (in_array($cause, ['unresolved_taxonomy_label', 'missing_current_assignment_evaluation'], true)) {
                $signals[] = 'audit:taxonomy:'.$cause;
            }
        }

        if ($this->evidenceSnapshotLooksMalformed($audit) && $this->currentProductIsUsable($product)) {
            $signals[] = 'audit:malformed_evidence_snapshot';
        }

        $semantic = is_array($audit->semantic_evaluation) ? $audit->semantic_evaluation : [];

        if ($semantic === [] && $audit->gift_score_components === []) {
            $signals[] = 'audit:missing_semantic_payload';
        }

        return array_values(array_unique($signals));
    }

    private function currentProductIsUsable(Product $product): bool
    {
        return trim((string) $product->name) !== ''
            && ($product->price_amount !== null && (float) $product->price_amount > 0)
            && $product->affiliateLinks->isNotEmpty()
            && $product->categories->isNotEmpty()
            && ! $this->looksLikeDevelopmentPlaceholder($product);
    }

    private function looksLikeDevelopmentPlaceholder(Product $product): bool
    {
        $haystack = strtolower(trim(implode(' ', array_filter([
            $product->name,
            $product->slug,
            $product->sku,
            $product->brand,
            $product->short_description,
        ]))));

        if ($haystack === '') {
            return true;
        }

        return (bool) preg_match(
            '/\b(test product|dummy product|placeholder|lorem ipsum|dev only|development product|sample gift|sample draft|local development)\b/',
            $haystack,
        ) || in_array($haystack, ['test', 'dummy', 'placeholder', 'sample'], true);
    }

    private function evidenceSnapshotLooksMalformed(ProductCurationAudit $audit): bool
    {
        $snapshot = $audit->evidence_snapshot;

        return ! is_array($snapshot)
            || $snapshot === []
            || ! isset($snapshot['product_id'], $snapshot['name'])
            || (int) ($snapshot['product_id'] ?? 0) !== (int) $audit->product_id
            || trim((string) ($snapshot['name'] ?? '')) === '';
    }

    private function hasIssueCode(ProductCurationAudit $audit, string $code): bool
    {
        return collect($this->issues($audit))->contains(
            fn (array $issue): bool => ($issue['code'] ?? null) === $code,
        );
    }

    /**
     * @param  list<string>  $signals
     */
    private function hasSignalPrefix(array $signals, string $prefix): bool
    {
        return collect($signals)->contains(
            fn (string $signal): bool => str_starts_with($signal, $prefix),
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
