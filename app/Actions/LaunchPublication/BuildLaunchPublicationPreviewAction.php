<?php

namespace App\Actions\LaunchPublication;

use App\Actions\Product\AssessProductPublicationRequirementsAction;
use App\Enums\ProductStatus;
use App\LaunchPublication\LaunchPublicationReadinessRow;
use App\Models\Product;

class BuildLaunchPublicationPreviewAction
{
    public function __construct(
        private CaptureLaunchCatalogFingerprintAction $captureFingerprint,
        private InspectPublishedCatalogAction $inspectPublished,
        private QueryLaunchPublicationCandidatesAction $queryCandidates,
        private BuildLaunchPublicationReadinessReportAction $buildReadiness,
        private SelectLaunchPublicationCohortAction $selectCohort,
        private BuildLaunchCatalogCoverageAction $buildCoverage,
        private AssessProductPublicationRequirementsAction $assessPublication,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $fingerprint = $this->captureFingerprint->execute();
        $published = $this->inspectPublished->execute();
        $candidates = $this->queryCandidates->execute();
        $readiness = $this->buildReadiness->execute($candidates);
        $cohort = $this->selectCohort->execute($readiness);

        $proposedIds = array_values(array_unique(array_merge(
            array_column($published['products'], 'product_id'),
            $cohort['product_ids'],
        )));

        $feature = $this->featureReport($readiness['rows']);
        $coverage = $this->buildCoverage->execute($proposedIds, includeLandingPages: false);

        return [
            'fingerprint' => $fingerprint->toArray(),
            'fingerprint_hash' => $fingerprint->hash,
            'catalog_counts' => $fingerprint->counts,
            'published' => $published,
            'feature_products' => $feature,
            'readiness' => [
                'retained_draft' => $readiness['retained_draft'],
                'publish_ready' => $readiness['publish_ready'],
                'blocked' => $readiness['blocked'],
                'blocker_counts' => $readiness['blocker_counts'],
                'blocker_groups' => $readiness['blocker_groups'],
                'rows' => array_map(
                    fn (LaunchPublicationReadinessRow $row): array => $row->toArray(),
                    $readiness['rows'],
                ),
            ],
            'cohort' => [
                'product_ids' => $cohort['product_ids'],
                'count' => count($cohort['product_ids']),
                'decision_counts' => $cohort['decision_counts'],
                'concept_warnings' => $cohort['concept_warnings'],
                'rows' => array_map(
                    fn (LaunchPublicationReadinessRow $row): array => $row->toArray(),
                    $cohort['rows'],
                ),
            ],
            'coverage_preview' => $coverage,
            '_internal' => [
                'fingerprint' => $fingerprint,
                'readiness' => $readiness,
                'cohort' => $cohort,
            ],
        ];
    }

    /**
     * @param  list<LaunchPublicationReadinessRow>  $rows
     * @return list<array<string, mixed>>
     */
    private function featureReport(array $rows): array
    {
        $byId = collect($rows)->keyBy('productId');

        return $this->inspectPublished->featureProducts()
            ->map(function (Product $product) use ($byId): array {
                $row = $byId->get($product->id);
                $assessment = $row instanceof LaunchPublicationReadinessRow
                    ? null
                    : $this->assessPublication->execute($product);

                return [
                    'product_id' => (int) $product->id,
                    'title' => (string) $product->name,
                    'status' => $product->status?->value,
                    'human_merchandising_role' => $product->currentCurationDecision?->catalog_role?->value,
                    'readiness' => $product->status === ProductStatus::Published
                        ? 'already_published'
                        : ($row instanceof LaunchPublicationReadinessRow
                            ? ($row->ready ? 'READY' : 'BLOCKED')
                            : (($assessment['error_codes'] ?? []) === [] ? 'READY' : 'BLOCKED')),
                    'blocking_reasons' => $row instanceof LaunchPublicationReadinessRow
                        ? $row->blockingReasons
                        : ($assessment['error_messages'] ?? []),
                ];
            })
            ->values()
            ->all();
    }
}
