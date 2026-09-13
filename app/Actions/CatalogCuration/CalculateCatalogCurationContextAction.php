<?php

namespace App\Actions\CatalogCuration;

use App\Enums\ProductCurationAuditOutcome;
use App\Models\ProductCurationAudit;
use Illuminate\Support\Collection;

class CalculateCatalogCurationContextAction
{
    /**
     * @var array<string, Collection<int, ProductCurationAudit>>
     */
    private array $corpusCache = [];

    /**
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
        $semantic = $audit->semantic_evaluation ?? [];
        $concept = (string) ($audit->concept_key ?? $semantic['concept_key'] ?? '');
        $priceBand = $this->priceBand($audit->evidence_snapshot['price']['amount'] ?? null);
        $strongTaxonomyIds = $this->strongTaxonomyIds($semantic);
        $intents = array_values(array_unique($semantic['gift_intents'] ?? []));
        $conceptPeers = [];
        $budgetPeers = [];
        $taxonomyPeers = [];
        $intentPeers = [];

        foreach ($corpus as $candidate) {
            if ((int) $candidate->product_id === (int) $audit->product_id) {
                continue;
            }

            $candidateSemantic = $candidate->semantic_evaluation ?? [];
            $candidateConcept = (string) ($candidate->concept_key ?? $candidateSemantic['concept_key'] ?? '');

            if ($concept !== '' && $candidateConcept === $concept) {
                $conceptPeers[] = (int) $candidate->product_id;

                if ($priceBand !== null && $this->priceBand($candidate->evidence_snapshot['price']['amount'] ?? null) === $priceBand) {
                    $budgetPeers[] = (int) $candidate->product_id;
                }
            }

            if ($strongTaxonomyIds !== [] && array_intersect($strongTaxonomyIds, $this->strongTaxonomyIds($candidateSemantic)) !== []) {
                $taxonomyPeers[] = (int) $candidate->product_id;
            }

            if ($intents !== [] && $this->sameValues($intents, $candidateSemantic['gift_intents'] ?? [])) {
                $intentPeers[] = (int) $candidate->product_id;
            }
        }

        $conceptPeers = $this->uniqueIds($conceptPeers);
        $budgetPeers = $this->uniqueIds($budgetPeers);
        $taxonomyPeers = $this->uniqueIds($taxonomyPeers);
        $intentPeers = $this->uniqueIds($intentPeers);
        $maxima = (array) config('catalog_curation.catalog_value.factors');
        $saturationScores = (array) config('catalog_curation.catalog_value.saturation_scores', []);
        $saturationKey = min(count($conceptPeers), max(array_map('intval', array_keys($saturationScores))));
        $differentiationScores = (array) config('catalog_curation.catalog_value.differentiation_scores', []);
        $differentiationStrength = (string) ($semantic['differentiation_strength'] ?? 'none');
        $intentScores = (array) config('catalog_curation.catalog_value.intent_peer_scores', []);
        $nicheStrength = (string) ($semantic['niche_contribution'] ?? 'none');
        $nicheScores = (array) config("catalog_curation.catalog_value.niche_scores.{$nicheStrength}", [0 => 0]);
        $coveredStrongIds = [];

        foreach ($corpus as $candidate) {
            if ((int) $candidate->product_id === (int) $audit->product_id) {
                continue;
            }

            $coveredStrongIds = array_merge(
                $coveredStrongIds,
                array_intersect($strongTaxonomyIds, $this->strongTaxonomyIds($candidate->semantic_evaluation ?? [])),
            );
        }

        $coveredStrongIds = array_values(array_unique($coveredStrongIds));
        $uncoveredStrongIds = array_values(array_diff($strongTaxonomyIds, $coveredStrongIds));
        $taxonomyCoverageRatio = $strongTaxonomyIds === [] ? 0 : count($uncoveredStrongIds) / count($strongTaxonomyIds);

        $factors = [
            'saturation_novelty' => (int) ($saturationScores[$saturationKey] ?? 0),
            'differentiation' => min(
                (int) $maxima['differentiation'],
                (int) ($differentiationScores[$differentiationStrength] ?? 0),
            ),
            'budget_gap' => $priceBand !== null && $budgetPeers === [] ? (int) $maxima['budget_gap'] : 0,
            'taxonomy_gap' => (int) floor($taxonomyCoverageRatio * (int) $maxima['taxonomy_gap']),
            'intents' => $intents === []
                ? 0
                : min((int) $maxima['intents'], $this->scoreForPeerCount(count($intentPeers), $intentScores)),
            'niche' => min(
                (int) $maxima['niche'],
                $this->scoreForPeerCount(count($conceptPeers), $nicheScores),
            ),
        ];
        $peerIds = [
            'concept' => $conceptPeers,
            'budget_band' => $budgetPeers,
            'taxonomy_signature' => $taxonomyPeers,
            'gift_intents' => $intentPeers,
        ];
        $snapshot = [
            'run_id' => $audit->run_id,
            'corpus_product_count' => $corpus->count(),
            'concept_key' => $concept,
            'price_band' => $priceBand,
            'strong_taxonomy_ids' => $strongTaxonomyIds,
            'uncovered_strong_taxonomy_ids' => $uncoveredStrongIds,
            'gift_intents' => $intents,
            'differentiation_strength' => $differentiationStrength,
            'niche_contribution' => $nicheStrength,
            'signals' => [
                'concept_oversaturated' => count($conceptPeers) >= (int) config('catalog_curation.thresholds.concept_oversaturated_peers', 5),
                'possible_concept_duplicate' => count($conceptPeers) >= (int) config('catalog_curation.thresholds.possible_duplicate_peers', 1),
                'oversaturation_clear' => $concept !== '',
            ],
            'generated_at' => now()->toIso8601String(),
        ];

        return [
            'score' => array_sum($factors),
            'factors' => $factors,
            'peer_product_ids' => $peerIds,
            'peer_counts' => array_map('count', $peerIds),
            'snapshot' => $snapshot,
            'fingerprint' => $this->contextFingerprint($audit, $corpus, $snapshot),
        ];
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
            ->each(function (ProductCurationAudit $audit) use ($current): void {
                if (! $current->has($audit->product_id)) {
                    $current->put($audit->product_id, $audit);
                }
            });

        return $this->corpusCache[$cacheKey] = $current->values();
    }

    /**
     * @param  array<string, mixed>  $semantic
     * @return list<string>
     */
    private function strongTaxonomyIds(array $semantic): array
    {
        $signature = [];

        foreach (['relationships', 'occasions', 'interests', 'gift_types'] as $dimension) {
            foreach (array_merge(
                $semantic['current_taxonomy_evaluations'][$dimension] ?? [],
                $semantic['taxonomy_suggestions'][$dimension] ?? [],
            ) as $fit) {
                if (($fit['strength'] ?? null) === 'strong' && isset($fit['id'])) {
                    $signature[] = $dimension.':'.(int) $fit['id'];
                }
            }
        }

        sort($signature);

        return array_values(array_unique($signature));
    }

    private function priceBand(mixed $amount): ?string
    {
        if (! is_numeric($amount)) {
            return null;
        }

        $price = (float) $amount;

        foreach ((array) config('catalog_curation.price_bands', []) as $band) {
            $minimum = $band['min'] ?? null;
            $maximum = $band['max'] ?? null;

            if (($minimum === null || $price >= (float) $minimum) && ($maximum === null || $price <= (float) $maximum)) {
                return (string) $band['slug'];
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function uniqueIds(array $ids): array
    {
        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function sameValues(array $left, array $right): bool
    {
        sort($left);
        sort($right);

        return $left === $right;
    }

    /**
     * @param  array<int|string, int>  $scores
     */
    private function scoreForPeerCount(int $peerCount, array $scores): int
    {
        $score = 0;

        foreach ($scores as $minimumPeers => $candidateScore) {
            if ($peerCount >= (int) $minimumPeers) {
                $score = (int) $candidateScore;
            }
        }

        return $score;
    }

    /**
     * @param  Collection<int, ProductCurationAudit>  $corpus
     * @param  array<string, mixed>  $snapshot
     */
    private function contextFingerprint(ProductCurationAudit $audit, Collection $corpus, array $snapshot): string
    {
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
            'inputs' => $inputs,
            'target' => [
                'concept_key' => $snapshot['concept_key'],
                'price_band' => $snapshot['price_band'],
                'strong_taxonomy_ids' => $snapshot['strong_taxonomy_ids'],
                'gift_intents' => $snapshot['gift_intents'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
