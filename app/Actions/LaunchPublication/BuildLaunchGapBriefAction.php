<?php

namespace App\Actions\LaunchPublication;

use App\Actions\CatalogCuration\ResolveAcceptedCurationAuditRunAction;
use App\Enums\ProductCurationDecision;
use App\LaunchPublication\LaunchPublicationReadinessRow;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;

class BuildLaunchGapBriefAction
{
    public function __construct(
        private ResolveAcceptedCurationAuditRunAction $resolveAcceptedRun,
    ) {}

    /**
     * @param  array{
     *     retained_draft: int,
     *     publish_ready: int,
     *     blocked: int,
     *     blocker_counts: array<string, int>,
     *     blocker_groups: array<string, int>,
     *     rows: list<LaunchPublicationReadinessRow>
     * }  $readiness
     * @param  array<string, mixed>  $coverage
     * @return array{
     *     readiness_backlog: array<string, list<array<string, mixed>>>,
     *     sourcing_backlog: array<string, mixed>
     * }
     */
    public function execute(array $readiness, array $coverage): array
    {
        return [
            'readiness_backlog' => $this->readinessBacklog($readiness['rows']),
            'sourcing_backlog' => $this->sourcingBacklog($coverage),
        ];
    }

    /**
     * @param  list<LaunchPublicationReadinessRow>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function readinessBacklog(array $rows): array
    {
        $groups = [
            'editorial' => [],
            'seo' => [],
            'commerce' => [],
            'image' => [],
            'taxonomy' => [],
            'affiliate' => [],
            'other' => [],
        ];

        foreach ($rows as $row) {
            if ($row->ready) {
                continue;
            }

            $item = [
                'product_id' => $row->productId,
                'title' => $row->title,
                'human_decision' => $row->humanDecision,
                'blocking_codes' => $row->blockingCodes,
                'blocking_reasons' => $row->blockingReasons,
            ];

            $assigned = false;
            foreach ($row->blockingCodes as $code) {
                $group = match ($code) {
                    'missing_name' => 'editorial',
                    'missing_slug' => 'seo',
                    'no_image' => 'image',
                    'no_active_affiliate_link' => 'affiliate',
                    'missing_primary_category', 'invalid_primary_category', 'classification_not_publishable' => 'taxonomy',
                    default => 'other',
                };
                $groups[$group][] = $item;
                $assigned = true;
            }

            if (! $assigned) {
                $groups['other'][] = $item;
            }
        }

        foreach ($groups as $key => $items) {
            $groups[$key] = collect($items)->unique('product_id')->values()->all();
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $coverage
     * @return array<string, mixed>
     */
    private function sourcingBacklog(array $coverage): array
    {
        $removeCandidates = ProductCurationDecisionRecord::query()
            ->current()
            ->where('decision', ProductCurationDecision::RemoveCandidate)
            ->with('product')
            ->orderBy('product_id')
            ->get();

        $run = $this->resolveAcceptedRun->execute();
        $audits = $run instanceof ProductCurationAuditRun
            ? ProductCurationAudit::query()
                ->where('run_id', $run->id)
                ->whereIn('product_id', $removeCandidates->pluck('product_id'))
                ->get()
                ->keyBy('product_id')
            : collect();

        $replacements = [];
        foreach ($removeCandidates as $decision) {
            $product = $decision->product;
            if (! $product instanceof Product) {
                continue;
            }

            $audit = $audits->get($product->id);
            $replacements[] = [
                'product_id' => (int) $product->id,
                'title' => (string) $product->name,
                'status' => $product->status?->value,
                'weakness' => $decision->reason_notes ?: implode(', ', (array) $decision->reason_codes),
                'replacement_should_improve' => 'A stronger giftable alternative in the same merchandising neighborhood, without the recorded weakness.',
                'desired_budget' => is_array($audit?->catalog_context_snapshot)
                    ? ($audit->catalog_context_snapshot['price_band'] ?? null)
                    : null,
                'desired_taxonomy_context' => $this->taxonomyContext($audit),
                'desired_gift_intent' => array_values(array_filter(
                    (array) ($audit?->gift_intents ?? []),
                    fn (mixed $intent): bool => is_string($intent),
                )),
            ];
        }

        $gaps = collect($coverage['holes'] ?? [])
            ->groupBy('level')
            ->map(fn ($rows) => $rows->values()->all())
            ->all();

        return [
            'remove_candidate_count' => $removeCandidates->count(),
            'replacement_targets' => $replacements,
            'coverage_gaps' => [
                'critical' => $gaps['critical'] ?? [],
                'high' => $gaps['high'] ?? [],
                'medium' => $gaps['medium'] ?? [],
                'low' => $gaps['low'] ?? [],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function taxonomyContext(?ProductCurationAudit $audit): array
    {
        if (! $audit instanceof ProductCurationAudit) {
            return [];
        }

        return collect(['relationships', 'occasions', 'interests', 'gift_types'])
            ->flatMap(fn (string $dimension) => collect(data_get($audit->evidence_snapshot, "taxonomy.{$dimension}", [])))
            ->filter(fn (mixed $item): bool => is_array($item) && filled($item['name'] ?? null))
            ->map(fn (array $item): string => (string) $item['name'])
            ->unique()
            ->take(6)
            ->values()
            ->all();
    }
}
