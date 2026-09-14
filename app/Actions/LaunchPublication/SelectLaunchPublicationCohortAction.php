<?php

namespace App\Actions\LaunchPublication;

use App\Enums\ProductCurationDecision;
use App\LaunchPublication\LaunchPublicationReadinessRow;

class SelectLaunchPublicationCohortAction
{
    /**
     * @param  array{
     *     retained_draft: int,
     *     publish_ready: int,
     *     blocked: int,
     *     blocker_counts: array<string, int>,
     *     blocker_groups: array<string, int>,
     *     rows: list<LaunchPublicationReadinessRow>
     * }  $readiness
     * @return array{
     *     product_ids: list<int>,
     *     rows: list<LaunchPublicationReadinessRow>,
     *     concept_warnings: list<array{concept_key: ?string, concept_label: ?string, product_ids: list<int>, count: int}>,
     *     decision_counts: array<string, int>
     * }
     */
    public function execute(array $readiness): array
    {
        $ready = collect($readiness['rows'])
            ->filter(fn (LaunchPublicationReadinessRow $row): bool => $row->ready)
            ->sortBy(fn (LaunchPublicationReadinessRow $row): array => [
                $this->decisionRank($row->humanDecision),
                -1 * ($row->giftScore ?? 0),
                -1 * ($row->catalogValue ?? 0),
                $row->productId,
            ])
            ->values();

        $threshold = (int) config('gift_publication.launch.concept_cluster_warning_threshold', 3);

        $conceptWarnings = $ready
            ->filter(fn (LaunchPublicationReadinessRow $row): bool => filled($row->conceptKey))
            ->groupBy(fn (LaunchPublicationReadinessRow $row): string => (string) $row->conceptKey)
            ->filter(fn ($group): bool => $group->count() >= $threshold)
            ->map(fn ($group, string $key): array => [
                'concept_key' => $key,
                'concept_label' => $group->first()?->conceptLabel,
                'product_ids' => $group->pluck('productId')->values()->all(),
                'count' => $group->count(),
            ])
            ->values()
            ->all();

        $decisionCounts = [
            ProductCurationDecision::Feature->value => 0,
            ProductCurationDecision::Keep->value => 0,
            ProductCurationDecision::KeepNiche->value => 0,
        ];

        foreach ($ready as $row) {
            if (isset($decisionCounts[(string) $row->humanDecision])) {
                $decisionCounts[(string) $row->humanDecision]++;
            }
        }

        return [
            'product_ids' => $ready->pluck('productId')->all(),
            'rows' => $ready->all(),
            'concept_warnings' => $conceptWarnings,
            'decision_counts' => $decisionCounts,
        ];
    }

    private function decisionRank(?string $decision): int
    {
        return match ($decision) {
            ProductCurationDecision::Feature->value => 0,
            ProductCurationDecision::Keep->value => 1,
            ProductCurationDecision::KeepNiche->value => 2,
            default => 9,
        };
    }
}
