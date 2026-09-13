<?php

namespace App\Actions\CatalogCuration;

use App\Enums\GiftIntent;
use App\Enums\ProductCurationAuditOutcome;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\ProductCurationAudit;
use App\Models\Relationship;
use Illuminate\Support\Collection;

class CalculateRelativeCatalogCurationContextAction
{
    /**
     * @var array<string, Collection<int, ProductCurationAudit>>
     */
    private array $corpusCache = [];

    /**
     * @var array<string, list<int>>|null
     */
    private ?array $activeTaxonomyIds = null;

    /**
     * Candidate-only calculation. Calling this action never persists data.
     *
     * @return array{
     *   score: int,
     *   factors: array<string, int>,
     *   peer_product_ids: array<string, list<int>>,
     *   peer_counts: array<string, int>,
     *   snapshot: array<string, mixed>,
     *   fingerprint: string
     * }
     */
    public function execute(ProductCurationAudit $audit, bool $preferCurrentRun = true): array
    {
        $corpus = $this->corpus($preferCurrentRun ? $audit->run_id : null);
        $profiles = $corpus
            ->mapWithKeys(fn (ProductCurationAudit $candidate): array => [
                (int) $candidate->product_id => $this->profile($candidate),
            ])
            ->all();
        $targetId = (int) $audit->product_id;
        $target = $profiles[$targetId] ?? $this->profile($audit);
        $others = array_filter(
            $profiles,
            fn (array $profile): bool => $profile['product_id'] !== $targetId,
        );
        $config = (array) config('catalog_curation.catalog_value.relative_candidate');
        $maxima = (array) config('catalog_curation.catalog_value.factors');

        $conceptPeers = $target['concept_key'] === ''
            ? []
            : $this->matchingProductIds(
                $others,
                fn (array $profile): bool => $profile['concept_key'] === $target['concept_key'],
            );
        $exactConcept = $this->exactConceptScore(
            $target['concept_key'],
            count($conceptPeers) + 1,
            (int) $config['concept_exact_max'],
            (int) $config['concept_saturation_count'],
        );

        $taxonomy = $this->taxonomyScarcity($target, $others, $config);
        $intents = $this->intentScarcity($target, $others, $config);
        $budget = $this->budgetScarcity($target, $others, $config);
        $contextSignals = [
            ...array_values($taxonomy['dimension_scores']),
            $intents['aggregate'],
            $budget['band_scarcity'],
        ];
        $contextScarcity = $this->weightedTop(
            $contextSignals,
            (array) $config['context_weights'],
        );
        $conceptContextMax = (int) $maxima['saturation_novelty'] - (int) $config['concept_exact_max'];
        $conceptContext = $conceptContextMax * $contextScarcity;

        $differentiation = $this->differentiationScore($target, $config);
        $taxonomyPoints = (int) $maxima['taxonomy_gap'] * $taxonomy['aggregate'];
        $intentPoints = (int) $maxima['intents'] * $intents['aggregate'];
        $budgetBandPoints = (int) $maxima['budget_gap']
            * (float) $config['budget_band_weight']
            * $budget['band_scarcity'];
        $budgetContextPoints = (int) $maxima['budget_gap']
            * (float) $config['budget_context_weight']
            * $budget['context_scarcity'];

        if ($budget['comparable_signals'] === 0) {
            $budgetBandPoints = min((float) $config['price_only_cap'], $budgetBandPoints);
            $budgetContextPoints = 0.0;
        }

        [$nicheAnchor, $nicheRefinement] = $this->nicheComponents($target, $contextScarcity, $config);
        $rawScarcityPoints = $conceptContext
            + $taxonomyPoints
            + $intentPoints
            + $budgetContextPoints
            + $nicheRefinement;
        $scarcityScale = $rawScarcityPoints > 0
            ? min(1.0, (float) $config['shared_scarcity_cap'] / $rawScarcityPoints)
            : 1.0;

        $factors = [
            'saturation_novelty' => $this->boundedRound(
                $exactConcept + ($conceptContext * $scarcityScale),
                (int) $maxima['saturation_novelty'],
            ),
            'differentiation' => $differentiation,
            'budget_gap' => $this->boundedRound(
                $budgetBandPoints + ($budgetContextPoints * $scarcityScale),
                (int) $maxima['budget_gap'],
            ),
            'taxonomy_gap' => $this->boundedRound(
                $taxonomyPoints * $scarcityScale,
                (int) $maxima['taxonomy_gap'],
            ),
            'intents' => $this->boundedRound(
                $intentPoints * $scarcityScale,
                (int) $maxima['intents'],
            ),
            'niche' => $this->boundedRound(
                $nicheAnchor + ($nicheRefinement * $scarcityScale),
                (int) $maxima['niche'],
            ),
        ];

        $budgetPeers = $target['budget_band'] === null
            ? []
            : $this->matchingProductIds(
                $others,
                fn (array $profile): bool => $profile['budget_band'] === $target['budget_band'],
            );
        $taxonomyPeers = $this->matchingProductIds(
            $others,
            fn (array $profile): bool => $this->sharesTaxonomy($target, $profile),
        );
        $intentPeers = $this->matchingProductIds(
            $others,
            fn (array $profile): bool => array_intersect($target['gift_intents'], $profile['gift_intents']) !== [],
        );
        $peerIds = [
            'concept' => array_slice($conceptPeers, 0, 25),
            'budget_band' => array_slice($budgetPeers, 0, 25),
            'taxonomy_signature' => array_slice($taxonomyPeers, 0, 25),
            'gift_intents' => array_slice($intentPeers, 0, 25),
        ];
        $peerCounts = [
            'concept' => count($conceptPeers),
            'budget_band' => count($budgetPeers),
            'taxonomy_signature' => count($taxonomyPeers),
            'gift_intents' => count($intentPeers),
        ];
        $snapshot = [
            'run_id' => $audit->run_id,
            'corpus_product_count' => count($profiles),
            'target_excluded_from_all_coverage_counts' => true,
            'concept_key' => $target['concept_key'],
            'price_band' => $this->operationalPriceBand($audit->evidence_snapshot['price']['amount'] ?? null),
            'budget_coverage_band' => $target['budget_band'],
            'strong_taxonomy_ids' => $this->flattenTaxonomy($target['taxonomy']),
            'gift_intents' => $target['gift_intents'],
            'differentiation_strength' => $target['differentiation_strength'],
            'niche_contribution' => $target['niche_contribution'],
            'relative_coverage' => [
                'concept_peer_count' => count($conceptPeers),
                'concept_exact_component' => $this->rounded($exactConcept),
                'concept_context_component_raw' => $this->rounded($conceptContext),
                'context_scarcity' => $this->rounded($contextScarcity),
                'taxonomy' => $taxonomy,
                'intents' => $intents,
                'budget' => $budget,
                'differentiation_signal_count' => count($target['differentiation_signals']),
                'niche_anchor' => $this->rounded($nicheAnchor),
                'niche_refinement_raw' => $this->rounded($nicheRefinement),
                'shared_scarcity_points_raw' => $this->rounded($rawScarcityPoints),
                'shared_scarcity_cap' => (float) $config['shared_scarcity_cap'],
                'shared_scarcity_scale' => $this->rounded($scarcityScale),
            ],
            'signals' => [
                'concept_oversaturated' => count($conceptPeers) >= (int) config('catalog_curation.thresholds.concept_oversaturated_peers', 5),
                'possible_concept_duplicate' => count($conceptPeers) >= (int) config('catalog_curation.thresholds.possible_duplicate_peers', 1),
                'oversaturation_clear' => $target['concept_key'] !== '',
            ],
            'generated_at' => now()->toIso8601String(),
        ];

        return [
            'score' => array_sum($factors),
            'factors' => $factors,
            'peer_product_ids' => $peerIds,
            'peer_counts' => $peerCounts,
            'snapshot' => $snapshot,
            'fingerprint' => $this->contextFingerprint($audit, $corpus, $snapshot, $config),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $others
     * @param  array<string, mixed>  $config
     * @return array{aggregate: float, dimension_scores: array<string, float>, details: array<string, list<array<string, int|float>>>}
     */
    private function taxonomyScarcity(array $target, array $others, array $config): array
    {
        $dimensionScores = [];
        $details = [];

        foreach ($this->activeTaxonomyIds() as $dimension => $activeIds) {
            $referenceCounts = $this->dimensionReferenceCounts(
                $activeIds,
                $target['taxonomy'][$dimension],
                $others,
                fn (array $profile, int $id): bool => in_array($id, $profile['taxonomy'][$dimension], true),
            );
            $selected = [];

            foreach ($target['taxonomy'][$dimension] as $id) {
                if (! array_key_exists($id, $referenceCounts)) {
                    continue;
                }

                $count = $referenceCounts[$id];
                $selected[] = [
                    'id' => $id,
                    'existing_strong_count' => $count,
                    'relative_scarcity' => $this->rounded($this->relativeScarcity($count, array_values($referenceCounts))),
                ];
            }

            usort($selected, fn (array $left, array $right): int => $right['relative_scarcity'] <=> $left['relative_scarcity']);
            $details[$dimension] = array_slice($selected, 0, 2);
            $dimensionScores[$dimension] = $this->weightedTop(
                array_column($selected, 'relative_scarcity'),
                (array) $config['taxonomy_assignment_weights'],
            );
        }

        return [
            'aggregate' => $this->rounded($this->weightedTop(
                array_values($dimensionScores),
                (array) $config['dimension_weights'],
            )),
            'dimension_scores' => array_map($this->rounded(...), $dimensionScores),
            'details' => $details,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $others
     * @param  array<string, mixed>  $config
     * @return array{aggregate: float, details: list<array{intent: string, existing_count: int, relative_scarcity: float}>}
     */
    private function intentScarcity(array $target, array $others, array $config): array
    {
        $canonicalIntents = array_column(GiftIntent::cases(), 'value');
        $referenceCounts = $this->dimensionReferenceCounts(
            $canonicalIntents,
            $target['gift_intents'],
            $others,
            fn (array $profile, string $intent): bool => in_array($intent, $profile['gift_intents'], true),
        );
        $details = [];

        foreach ($target['gift_intents'] as $intent) {
            if (! array_key_exists($intent, $referenceCounts)) {
                continue;
            }

            $count = $referenceCounts[$intent];
            $details[] = [
                'intent' => $intent,
                'existing_count' => $count,
                'relative_scarcity' => $this->rounded($this->relativeScarcity($count, array_values($referenceCounts))),
            ];
        }

        usort($details, fn (array $left, array $right): int => $right['relative_scarcity'] <=> $left['relative_scarcity']);

        return [
            'aggregate' => $this->rounded($this->weightedTop(
                array_column($details, 'relative_scarcity'),
                (array) $config['intent_weights'],
            )),
            'details' => array_slice($details, 0, 2),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $others
     * @param  array<string, mixed>  $config
     * @return array{band: ?string, band_existing_count: int, band_scarcity: float, context_scarcity: float, comparable_signals: int, strongest_contexts: list<array<string, int|float>>}
     */
    private function budgetScarcity(array $target, array $others, array $config): array
    {
        $bandCounts = [];

        foreach ((array) $config['budget_bands'] as $band) {
            $slug = (string) $band['slug'];
            $bandCounts[$slug] = $this->countMatching(
                $others,
                fn (array $profile): bool => $profile['budget_band'] === $slug,
            );
        }

        $band = $target['budget_band'];

        if ($band === null) {
            return [
                'band' => null,
                'band_existing_count' => 0,
                'band_scarcity' => 0.0,
                'context_scarcity' => 0.0,
                'comparable_signals' => 0,
                'strongest_contexts' => [],
            ];
        }

        $contextDetails = [];

        foreach ($this->activeTaxonomyIds() as $dimension => $activeIds) {
            $referenceCounts = $this->dimensionReferenceCounts(
                $activeIds,
                $target['taxonomy'][$dimension],
                $others,
                fn (array $profile, int $id): bool => $profile['budget_band'] === $band
                    && in_array($id, $profile['taxonomy'][$dimension], true),
            );

            foreach ($target['taxonomy'][$dimension] as $id) {
                if (! array_key_exists($id, $referenceCounts)) {
                    continue;
                }

                $count = $referenceCounts[$id];
                $contextDetails[] = [
                    'context' => $dimension.':'.$id,
                    'same_band_existing_count' => $count,
                    'relative_scarcity' => $this->rounded($this->relativeScarcity($count, array_values($referenceCounts))),
                ];
            }
        }

        $intentCounts = $this->dimensionReferenceCounts(
            array_column(GiftIntent::cases(), 'value'),
            $target['gift_intents'],
            $others,
            fn (array $profile, string $intent): bool => $profile['budget_band'] === $band
                && in_array($intent, $profile['gift_intents'], true),
        );

        foreach ($target['gift_intents'] as $intent) {
            if (! array_key_exists($intent, $intentCounts)) {
                continue;
            }

            $count = $intentCounts[$intent];
            $contextDetails[] = [
                'context' => 'intent:'.$intent,
                'same_band_existing_count' => $count,
                'relative_scarcity' => $this->rounded($this->relativeScarcity($count, array_values($intentCounts))),
            ];
        }

        usort($contextDetails, fn (array $left, array $right): int => $right['relative_scarcity'] <=> $left['relative_scarcity']);

        return [
            'band' => $band,
            'band_existing_count' => $bandCounts[$band],
            'band_scarcity' => $this->rounded($this->relativeScarcity($bandCounts[$band], array_values($bandCounts))),
            'context_scarcity' => $this->rounded($this->weightedTop(
                array_column($contextDetails, 'relative_scarcity'),
                (array) $config['context_weights'],
            )),
            'comparable_signals' => count($contextDetails),
            'strongest_contexts' => array_slice($contextDetails, 0, 3),
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $config
     */
    private function differentiationScore(array $target, array $config): int
    {
        $range = $config['differentiation_ranges'][$target['differentiation_strength']] ?? [0, 0];
        $minimum = (int) $range[0];
        $maximum = (int) $range[1];
        $signalRatio = min(1.0, count($target['differentiation_signals']) / 4);

        return $this->boundedRound(
            $minimum + (($maximum - $minimum) * $signalRatio),
            (int) config('catalog_curation.catalog_value.factors.differentiation'),
        );
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $config
     * @return array{float, float}
     */
    private function nicheComponents(array $target, float $contextScarcity, array $config): array
    {
        $range = $config['niche_ranges'][$target['niche_contribution']] ?? [0, 0];
        $minimum = (float) $range[0];
        $maximum = (float) $range[1];

        return [$minimum, ($maximum - $minimum) * $contextScarcity];
    }

    private function exactConceptScore(string $concept, int $conceptCount, int $maximum, int $saturationCount): float
    {
        if ($concept === '' || $maximum <= 0 || $saturationCount <= 1) {
            return 0.0;
        }

        $decay = 1 - (log(max(1, $conceptCount)) / log($saturationCount));

        return $maximum * max(0.0, min(1.0, $decay));
    }

    /**
     * Tie-safe inverse mid-rank ECDF over the same-dimension reference set.
     * Unused zero-coverage values are omitted from that set so lightly covered
     * live contexts are not compared against empty vocabulary slots.
     * Fallback: fewer than three comparable values, or no spread, uses 1/(1+count).
     *
     * @param  list<int>  $referenceCounts
     */
    private function relativeScarcity(int $count, array $referenceCounts): float
    {
        if ($count === 0) {
            return 1.0;
        }

        if (count($referenceCounts) < 3 || count(array_unique($referenceCounts)) < 2) {
            return 1 / (1 + $count);
        }

        $greater = count(array_filter($referenceCounts, fn (int $reference): bool => $reference > $count));
        $equal = count(array_filter($referenceCounts, fn (int $reference): bool => $reference === $count));

        return ($greater + (0.5 * $equal)) / count($referenceCounts);
    }

    /**
     * @param  list<float|int>  $values
     * @param  list<float|int>  $weights
     */
    private function weightedTop(array $values, array $weights): float
    {
        $values = array_values(array_filter($values, fn (mixed $value): bool => is_numeric($value)));
        rsort($values, SORT_NUMERIC);
        $values = array_slice($values, 0, count($weights));

        if ($values === []) {
            return 0.0;
        }

        $usedWeights = array_slice($weights, 0, count($values));
        $weightTotal = array_sum($usedWeights);

        if ($weightTotal <= 0) {
            return 0.0;
        }

        return array_sum(array_map(
            fn (float|int $value, float|int $weight): float => (float) $value * (float) $weight,
            $values,
            $usedWeights,
        )) / $weightTotal;
    }

    /**
     * @template TValue of int|string
     *
     * @param  list<TValue>  $candidateIds
     * @param  list<TValue>  $targetIds
     * @param  array<int, array<string, mixed>>  $others
     * @param  callable(array<string, mixed>, TValue): bool  $matches
     * @return array<TValue, int>
     */
    private function dimensionReferenceCounts(array $candidateIds, array $targetIds, array $others, callable $matches): array
    {
        $counts = [];

        foreach ($candidateIds as $id) {
            $count = $this->countMatching(
                $others,
                fn (array $profile): bool => $matches($profile, $id),
            );

            if ($count > 0 || in_array($id, $targetIds, true)) {
                $counts[$id] = $count;
            }
        }

        return $counts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $profiles
     */
    private function countMatching(array $profiles, callable $matches): int
    {
        return count(array_filter($profiles, $matches));
    }

    /**
     * @param  array<int, array<string, mixed>>  $profiles
     * @return list<int>
     */
    private function matchingProductIds(array $profiles, callable $matches): array
    {
        $ids = [];

        foreach ($profiles as $profile) {
            if ($matches($profile)) {
                $ids[] = (int) $profile['product_id'];
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    private function sharesTaxonomy(array $target, array $candidate): bool
    {
        foreach (array_keys($this->activeTaxonomyIds()) as $dimension) {
            if (array_intersect($target['taxonomy'][$dimension], $candidate['taxonomy'][$dimension]) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(ProductCurationAudit $audit): array
    {
        $semantic = $audit->semantic_evaluation ?? [];

        return [
            'product_id' => (int) $audit->product_id,
            'concept_key' => (string) ($audit->concept_key ?? $semantic['concept_key'] ?? ''),
            'budget_band' => $this->candidateBudgetBand($audit->evidence_snapshot['price']['amount'] ?? null),
            'taxonomy' => $this->strongTaxonomyByDimension($semantic),
            'gift_intents' => $this->normalizedStrings($semantic['gift_intents'] ?? []),
            'differentiation_signals' => $this->normalizedStrings($semantic['differentiation_signals'] ?? []),
            'differentiation_strength' => (string) ($semantic['differentiation_strength'] ?? 'none'),
            'niche_contribution' => (string) ($semantic['niche_contribution'] ?? 'none'),
        ];
    }

    /**
     * @param  array<string, mixed>  $semantic
     * @return array<string, list<int>>
     */
    private function strongTaxonomyByDimension(array $semantic): array
    {
        $result = [];

        foreach ($this->activeTaxonomyIds() as $dimension => $activeIds) {
            $ids = [];

            foreach (array_merge(
                $semantic['current_taxonomy_evaluations'][$dimension] ?? [],
                $semantic['taxonomy_suggestions'][$dimension] ?? [],
            ) as $fit) {
                if (($fit['strength'] ?? null) === 'strong'
                    && isset($fit['id'])
                    && in_array((int) $fit['id'], $activeIds, true)) {
                    $ids[] = (int) $fit['id'];
                }
            }

            sort($ids);
            $result[$dimension] = array_values(array_unique($ids));
        }

        return $result;
    }

    /**
     * @return array<string, list<int>>
     */
    private function activeTaxonomyIds(): array
    {
        return $this->activeTaxonomyIds ??= [
            'relationships' => Relationship::query()->where('is_active', true)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'occasions' => Occasion::query()->where('is_active', true)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'interests' => Interest::query()->where('is_active', true)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'gift_types' => GiftType::query()->where('is_active', true)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ];
    }

    /**
     * @param  array<string, list<int>>  $taxonomy
     * @return list<string>
     */
    private function flattenTaxonomy(array $taxonomy): array
    {
        $ids = [];

        foreach ($taxonomy as $dimension => $dimensionIds) {
            foreach ($dimensionIds as $id) {
                $ids[] = $dimension.':'.$id;
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizedStrings(array $values): array
    {
        $normalized = array_map(
            fn (mixed $value): string => mb_strtolower(trim((string) $value)),
            $values,
        );
        $normalized = array_values(array_filter($normalized, fn (string $value): bool => $value !== ''));
        sort($normalized);

        return array_values(array_unique($normalized));
    }

    private function candidateBudgetBand(mixed $amount): ?string
    {
        return $this->priceBand(
            $amount,
            (array) config('catalog_curation.catalog_value.relative_candidate.budget_bands', []),
        );
    }

    private function operationalPriceBand(mixed $amount): ?string
    {
        return $this->priceBand($amount, (array) config('catalog_curation.price_bands', []));
    }

    /**
     * @param  list<array<string, mixed>>  $bands
     */
    private function priceBand(mixed $amount, array $bands): ?string
    {
        if (! is_numeric($amount)) {
            return null;
        }

        $price = (float) $amount;

        foreach ($bands as $band) {
            $minimum = $band['min'] ?? null;
            $maximum = $band['max'] ?? null;

            if (($minimum === null || $price >= (float) $minimum)
                && ($maximum === null || $price <= (float) $maximum)) {
                return (string) $band['slug'];
            }
        }

        return null;
    }

    /**
     * Current-run semantic-ready/completed rows supersede compatible completed history.
     *
     * @return Collection<int, ProductCurationAudit>
     */
    private function corpus(?string $runId): Collection
    {
        $cacheKey = $runId ?? 'latest-compatible';

        if (isset($this->corpusCache[$cacheKey])) {
            return $this->corpusCache[$cacheKey];
        }

        $semanticVersion = (string) config('catalog_curation.versions.semantic_evaluator');
        $promptVersion = (string) config('catalog_curation.versions.prompt');
        $current = collect();

        if ($runId !== null) {
            $current = ProductCurationAudit::query()
                ->where('run_id', $runId)
                ->whereIn('outcome', [
                    ProductCurationAuditOutcome::SemanticReady,
                    ProductCurationAuditOutcome::Completed,
                ])
                ->get()
                ->keyBy('product_id');
        }

        ProductCurationAudit::query()
            ->when($runId !== null, fn ($query) => $query->where('run_id', '!=', $runId))
            ->where('outcome', ProductCurationAuditOutcome::Completed)
            ->where('semantic_evaluator_version', $semanticVersion)
            ->where('prompt_version', $promptVersion)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get()
            ->each(function (ProductCurationAudit $candidate) use ($current): void {
                if (! $current->has($candidate->product_id)) {
                    $current->put($candidate->product_id, $candidate);
                }
            });

        return $this->corpusCache[$cacheKey] = $current->values();
    }

    /**
     * @param  Collection<int, ProductCurationAudit>  $corpus
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $config
     */
    private function contextFingerprint(
        ProductCurationAudit $audit,
        Collection $corpus,
        array $snapshot,
        array $config,
    ): string {
        $inputs = $corpus
            ->sortBy('product_id')
            ->map(fn (ProductCurationAudit $candidate): array => [
                'product_id' => (int) $candidate->product_id,
                'semantic_fingerprint' => $candidate->semantic_fingerprint,
                'concept_key' => $candidate->concept_key,
                'price_amount' => $candidate->evidence_snapshot['price']['amount'] ?? null,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'product_id' => (int) $audit->product_id,
            'scoring_version' => (string) config('catalog_curation.versions.scoring'),
            'context_version' => (string) config('catalog_curation.versions.context'),
            'candidate_config' => $config,
            'active_taxonomy_ids' => $this->activeTaxonomyIds(),
            'inputs' => $inputs,
            'target' => [
                'concept_key' => $snapshot['concept_key'],
                'budget_coverage_band' => $snapshot['budget_coverage_band'],
                'strong_taxonomy_ids' => $snapshot['strong_taxonomy_ids'],
                'gift_intents' => $snapshot['gift_intents'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function boundedRound(float $score, int $maximum): int
    {
        return max(0, min($maximum, (int) round($score)));
    }

    private function rounded(float $value): float
    {
        return round($value, 4);
    }
}
