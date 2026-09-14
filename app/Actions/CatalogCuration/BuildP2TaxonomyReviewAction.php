<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\P2TaxonomyReview;
use App\CatalogCuration\ProductTaxonomySnapshot;
use App\Enums\CurationIssueSeverity;
use App\Models\Product;
use App\Models\ProductCurationAudit;

class BuildP2TaxonomyReviewAction
{
    public function __construct(
        private CaptureProductTaxonomySnapshotAction $captureSnapshot,
    ) {}

    public function execute(Product $product, ProductCurationAudit $audit): P2TaxonomyReview
    {
        $snapshot = $this->captureSnapshot->execute($product);
        $semantic = is_array($audit->semantic_evaluation) ? $audit->semantic_evaluation : [];
        $currentFits = is_array($semantic['current_taxonomy_evaluations'] ?? null)
            ? $semantic['current_taxonomy_evaluations']
            : [];
        $suggestions = is_array($semantic['taxonomy_suggestions'] ?? null)
            ? $semantic['taxonomy_suggestions']
            : [];
        $differences = is_array($audit->taxonomy_differences) ? $audit->taxonomy_differences : [];

        $currentAssignments = [];
        $suggestedAdditions = [];

        foreach (['relationships', 'occasions', 'interests', 'gift_types'] as $dimension) {
            $currentAssignments[$dimension] = $this->assignmentRows(
                $snapshot,
                $dimension,
                $currentFits[$dimension] ?? [],
            );
            $suggestedAdditions[$dimension] = $this->suggestionRows(
                $snapshot->idsFor($dimension),
                $suggestions[$dimension] ?? [],
            );
        }

        $materialFindings = [];
        $applicabilityConflicts = [];

        foreach ($differences as $difference) {
            if (! is_array($difference)) {
                continue;
            }

            $severity = (string) ($difference['severity'] ?? '');
            $forcesReview = ($difference['forces_human_review'] ?? false) === true;
            $isMaterial = $forcesReview || in_array($severity, [
                CurationIssueSeverity::Material->value,
                CurationIssueSeverity::Critical->value,
                CurationIssueSeverity::Blocking->value,
            ], true);

            if (($difference['cause'] ?? null) === 'hard_applicability_conflict') {
                $applicabilityConflicts[] = (string) ($difference['reason'] ?? 'Authoritative applicability conflict.');
            }

            if (! $isMaterial) {
                continue;
            }

            $materialFindings[] = [
                'dimension' => (string) ($difference['dimension'] ?? 'taxonomy'),
                'name' => (string) data_get($difference, 'taxonomy.name', ''),
                'severity' => $severity !== '' ? $severity : 'material',
                'reason' => (string) ($difference['reason'] ?? ''),
                'cause' => (string) ($difference['cause'] ?? 'taxonomy_difference'),
            ];
        }

        return new P2TaxonomyReview(
            snapshot: $snapshot,
            currentAssignments: $currentAssignments,
            suggestedAdditions: $suggestedAdditions,
            materialFindings: $materialFindings,
            applicabilityConflicts: $applicabilityConflicts,
            hasAuthoritativeConflict: $applicabilityConflicts !== [],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $fits
     * @return list<array{id: int, name: string, slug: string, strength: ?string, reason: ?string, misleading: ?bool}>
     */
    private function assignmentRows(ProductTaxonomySnapshot $snapshot, string $dimension, mixed $fits): array
    {
        $fitsById = collect(is_array($fits) ? $fits : [])
            ->filter(fn (mixed $fit): bool => is_array($fit) && (int) ($fit['id'] ?? 0) > 0)
            ->keyBy(fn (array $fit): int => (int) $fit['id']);

        return collect($snapshot->idsFor($dimension))
            ->map(function (int $id) use ($snapshot, $dimension, $fitsById): array {
                $fit = $fitsById->get($id);

                return [
                    'id' => $id,
                    'name' => is_array($fit) && filled($fit['name'] ?? null)
                        ? (string) $fit['name']
                        : $snapshot->name($dimension, $id),
                    'slug' => is_array($fit) ? (string) ($fit['slug'] ?? '') : '',
                    'strength' => is_array($fit) ? ($fit['strength'] ?? null) : null,
                    'reason' => is_array($fit) ? ($fit['reason'] ?? null) : null,
                    'misleading' => is_array($fit)
                        ? (($fit['misleading_on_targeted_landing_page'] ?? null) === true ? true : (($fit['misleading_on_targeted_landing_page'] ?? null) === false ? false : null))
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $currentIds
     * @return list<array{id: int, name: string, slug: string, strength: ?string, reason: ?string}>
     */
    private function suggestionRows(array $currentIds, mixed $suggestions): array
    {
        return collect(is_array($suggestions) ? $suggestions : [])
            ->filter(fn (mixed $fit): bool => is_array($fit) && (int) ($fit['id'] ?? 0) > 0)
            ->reject(fn (array $fit): bool => in_array((int) $fit['id'], $currentIds, true))
            ->map(fn (array $fit): array => [
                'id' => (int) $fit['id'],
                'name' => (string) ($fit['name'] ?? '#'.$fit['id']),
                'slug' => (string) ($fit['slug'] ?? ''),
                'strength' => $fit['strength'] ?? null,
                'reason' => $fit['reason'] ?? null,
            ])
            ->values()
            ->all();
    }
}
