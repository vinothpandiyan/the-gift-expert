<?php

namespace App\Actions\RecipientEligibility;

use App\Actions\Discovery\ApplyRelevanceFirstPoolOrderingAction;
use App\Actions\Discovery\NormalizeDiscoveryFilterStateAction;
use App\Actions\Discovery\QueryDiscoveryListingProductsAction;
use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\DiscoveryRanking\DiscoveryRankingContext;
use App\Enums\AffiliateLinkStatus;
use App\Models\Product;
use App\Models\Relationship;
use App\RecipientEligibility\AuditStateMatrix;
use App\RecipientEligibility\RecipientEligibilityConfig;
use App\RecipientEligibility\RelationshipGuards;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only shadow audit. It never writes catalog data: eligibility behavior is
 * evaluated by temporarily overriding configuration in memory, running the real
 * discovery code paths, and restoring configuration afterwards.
 */
class AuditRecipientEligibilityAction
{
    public function __construct(
        private ConstrainProductsByRecipientEligibilityAction $constrain,
        private ResolveRelationshipGuardsAction $resolveGuards,
        private BuildRecipientEligibilityBaselineAction $baseline,
        private QueryPublishedProductsByFiltersAction $queryProducts,
        private QueryDiscoveryListingProductsAction $queryListing,
        private NormalizeDiscoveryFilterStateAction $normalize,
        private ApplyRelevanceFirstPoolOrderingAction $orderPool,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(bool $includePool = true): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'wave_one' => RecipientEligibilityConfig::waveOneSlugs(),
            'funnel' => $this->funnel(),
            'contradictions' => $this->contradictions(),
            'matrix' => $this->matrix(),
            'finder' => $this->finder(),
            'pool' => $includePool ? $this->poolBenchmark() : [],
        ];
    }

    /**
     * Per relationship: explicit rows, the reason-code funnel for everything else
     * and the inferred remainder. Reasons are applied in order, so each product is
     * counted under the first reason that blocks it.
     *
     * @return array<string, array<string, mixed>>
     */
    public function funnel(): array
    {
        $funnel = [];

        foreach ($this->waveGuards() as $guards) {
            $base = $this->basePublished();
            $explicit = (clone $base)->where(fn (Builder $q) => $this->constrain->whereExplicit($q, $guards->relationshipId))->count();

            $pool = (clone $base)->whereNot(fn (Builder $q) => $this->constrain->whereExplicit($q, $guards->relationshipId));
            $reasons = [];

            foreach ($this->constrain->blockingReasons($guards) as $reason => $condition) {
                $reasons[$reason] = (clone $pool)->where(fn (Builder $q) => $condition($q))->count();
                $pool->whereNot(fn (Builder $q) => $condition($q));
            }

            $funnel[$guards->slug] = [
                'published_with_active_link' => (clone $base)->count(),
                'explicit' => $explicit,
                'blocked_by' => $reasons,
                'inferred' => $pool->count(),
            ];
        }

        return $funnel;
    }

    /**
     * Explicit pairs that contradict the relationship (gender mismatch, excluded gift
     * type, no applicable occasion). Soft child-exclusive explicit rows are reported
     * separately and never enforced.
     *
     * @return array<string, array<string, mixed>>
     */
    public function contradictions(): array
    {
        $report = [];

        foreach ($this->waveGuards() as $guards) {
            $explicit = fn (): Builder => $this->basePublished()
                ->where(fn (Builder $q) => $this->constrain->whereExplicit($q, $guards->relationshipId));

            $kinds = [];

            foreach ($this->constrain->contradictionKinds($guards) as $kind => $condition) {
                $kinds[$kind] = $explicit()
                    ->where(fn (Builder $q) => $condition($q))
                    ->orderBy('products.id')
                    ->get(['products.id', 'products.name'])
                    ->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name])
                    ->all();
            }

            $childOriented = $guards->childTypeIds === [] || $guards->childOrientedRelationship
                ? []
                : $explicit()
                    ->where(fn (Builder $q) => $this->constrain->whereChildExclusive($q, $guards))
                    ->orderBy('products.id')
                    ->get(['products.id', 'products.name'])
                    ->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name])
                    ->all();

            $report[$guards->slug] = [
                'explicit_total' => $explicit()->count(),
                'contradictions' => $kinds,
                'contradiction_total' => collect($kinds)->flatten(1)->pluck('id')->unique()->count(),
                'explicit_child_exclusive_review_only' => $childOriented,
            ];
        }

        return $report;
    }

    /**
     * Strict (all flags off) versus eligible (wave 1 fully on, in memory) for every
     * state in the matrix, including consistency checks and top-12 quality.
     *
     * @return array<string, array<string, mixed>>
     */
    public function matrix(): array
    {
        $rows = [];

        foreach (AuditStateMatrix::states() as $state) {
            $strict = $this->withConfig($this->strictOverrides(), fn () => $this->baseline->evaluateState($state));

            if ($strict === null) {
                continue;
            }

            $eligible = $this->withConfig($this->eligibleOverrides(), fn () => $this->baseline->evaluateState($state));

            $relationshipId = $this->stateRelationshipId($state);
            $recommended = 'recommended';
            $strictTop = array_slice($strict['sorts'][$recommended]['ids'], 0, $this->topN());
            $eligibleTop = array_slice($eligible['sorts'][$recommended]['ids'], 0, $this->topN());

            $sets = collect($eligible['sorts'])->map(fn (array $sort): array => collect($sort['ids'])->sort()->values()->all());

            $rows[$state['label']] = [
                'control' => $state['control'],
                'strict_total' => $strict['total'],
                'eligible_total' => $eligible['total'],
                'delta' => $eligible['total'] - $strict['total'],
                'unchanged' => $this->sameResults($strict, $eligible),
                'listing_unchanged' => $strict['total'] === $eligible['total'] && $strict['sorts'] === $eligible['sorts'],
                'facet_dimensions_changed' => $this->changedFacetDimensions($strict['facets'], $eligible['facets']),
                'consistency' => [
                    'ids_match_total' => collect($eligible['sorts'])->every(fn (array $sort): bool => count($sort['ids']) === $eligible['total']),
                    'same_set_across_sorts' => $sets->unique()->count() === 1,
                    'no_duplicates' => collect($eligible['sorts'])->every(fn (array $sort): bool => count($sort['ids']) === count(array_unique($sort['ids']))),
                ],
                'strict_top12' => $this->describeTop($strictTop, $relationshipId),
                'eligible_top12' => $this->describeTop($eligibleTop, $relationshipId),
                'facets_changed' => $strict['facets'] !== $eligible['facets'],
            ];
        }

        return $rows;
    }

    /**
     * Finder replays (stored golden sessions plus extra scenarios): strict versus
     * eligible top results, group counts (must not change) and interest-stage pool.
     *
     * @return array<string, array<string, mixed>>
     */
    public function finder(): array
    {
        $report = [];

        foreach ($this->baseline->finderInputs() as $label => $input) {
            $strict = $this->withConfig($this->strictOverrides(), fn () => $this->baseline->replay($input));
            $eligible = $this->withConfig($this->eligibleOverrides(), fn () => $this->baseline->replay($input));

            $strictIds = array_column($strict['top'], 'id');
            $eligibleIds = array_column($eligible['top'], 'id');
            $relationshipId = isset($input['relationship_id']) ? (int) $input['relationship_id'] : null;

            $report[$label] = [
                'strict_count' => count($strictIds),
                'eligible_count' => count($eligibleIds),
                'top12_identical' => $strictIds === $eligibleIds,
                'top12_added' => array_values(array_diff($eligibleIds, $strictIds)),
                'top12_removed' => array_values(array_diff($strictIds, $eligibleIds)),
                'same_ids_reordered' => $strictIds !== $eligibleIds && ! array_diff($strictIds, $eligibleIds) && ! array_diff($eligibleIds, $strictIds),
                'group_counts_strict' => $strict['group_counts'],
                'group_counts_eligible' => $eligible['group_counts'],
                'interest_stage_removed' => array_values(array_diff($strict['stage1_ids'], $eligible['stage1_ids'])),
                'interest_stage_added' => array_values(array_diff($eligible['stage1_ids'], $strict['stage1_ids'])),
                'strict_top12_incompatible' => $this->describeTop($strictIds, $relationshipId)['incompatible'],
                'eligible_top12_incompatible' => $this->describeTop($eligibleIds, $relationshipId)['incompatible'],
            ];
        }

        return $report;
    }

    /**
     * Stratified (round-robin by primary category) sample of inferred products per
     * relationship, ready for human review.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function samples(?int $size = null): array
    {
        $size ??= (int) config('recipient_eligibility.audit.sample_size', 50);
        $samples = [];

        foreach ($this->waveGuards() as $guards) {
            $products = $this->basePublished()
                ->whereNot(fn (Builder $q) => $this->constrain->whereExplicit($q, $guards->relationshipId))
                ->where(fn (Builder $q) => $this->constrain->whereInferred($q, $guards))
                ->with(['categories', 'recipientGenders', 'recipientTypes', 'occasions', 'interests'])
                ->orderBy('products.id')
                ->get();

            $samples[$guards->slug] = $this->stratify($products, $size)
                ->map(fn (Product $product): array => [
                    'relationship' => $guards->slug,
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'primary_category' => $this->primaryCategory($product),
                    'genders' => $product->recipientGenders->pluck('slug')->sort()->implode('|'),
                    'recipient_types' => $product->recipientTypes->pluck('slug')->sort()->implode('|'),
                    'occasions' => $product->occasions->pluck('slug')->sort()->implode('|'),
                    'interests' => $product->interests->pluck('slug')->sort()->implode('|'),
                    'review' => '',
                    'notes' => '',
                ])
                ->values()
                ->all();
        }

        return $samples;
    }

    /**
     * Candidate-pool benchmark through the real listing code path: pool sizes x
     * ordering, with retention against the uncapped ranking, how much of the
     * uncapped top 24 is inside the bounded pool, and timing.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function poolBenchmark(): array
    {
        $sizes = (array) config('recipient_eligibility.audit.pool_sizes', [40, 80, 500, 0]);
        $report = [];

        foreach ($this->poolStates() as $state) {
            $context = $this->baseline->listingContext($state['surface'], $state['slug']);

            if (! $context instanceof DiscoveryListingContext) {
                continue;
            }

            $listingState = $this->normalize->execute($context, DiscoveryListingQueryState::fromQuery($state['query']));
            $reference = $this->rankedIds($context, $listingState, 0, false);
            $rows = [];

            foreach ($sizes as $size) {
                foreach ([false, true] as $relevance) {
                    if ($size === 0 && $relevance) {
                        continue;
                    }

                    $timings = [];
                    $result = ['ids' => [], 'total' => 0];

                    for ($run = 0; $run < 3; $run++) {
                        $started = hrtime(true);
                        $result = $this->rankedIds($context, $listingState, $size, $relevance);
                        $timings[] = (hrtime(true) - $started) / 1_000_000;
                    }

                    sort($timings);
                    $rows[] = [
                        'pool_size' => $size === 0 ? 'uncapped' : $size,
                        'ordering' => $size === 0 ? 'n/a' : ($relevance ? 'relevance-first' : 'recency'),
                        'truncated' => $size !== 0 && $reference['total'] > $size,
                        'eligible_total' => $reference['total'],
                        'top12_retained' => count(array_intersect(array_slice($result['ids'], 0, 12), array_slice($reference['ids'], 0, 12))),
                        'top24_retained' => count(array_intersect($result['ids'], $reference['ids'])),
                        'pool_coverage_of_reference_top24' => $this->poolCoverage($context, $listingState, $size, $relevance, $reference['ids']),
                        'median_ms' => round($timings[1], 1),
                    ];
                }
            }

            $report[$state['label']] = $rows;
        }

        return $report;
    }

    /**
     * Renders the machine report as Markdown (before = strict, after = eligible).
     *
     * @param  array<string, mixed>  $report
     */
    public function toMarkdown(array $report): string
    {
        $lines = ['# Recipient Eligibility Shadow Audit', '', 'Generated: '.$report['generated_at'], ''];
        $lines[] = 'Before = strict explicit-pivot behavior (all flags off). After = wave 1 fully on, computed in memory. No data was written.';
        $lines[] = '';

        $lines[] = '## Funnel (published products with an active affiliate link)';
        $lines[] = '';

        foreach ($report['funnel'] as $slug => $row) {
            $blocked = collect($row['blocked_by'])->map(fn ($count, $reason): string => "{$reason} {$count}")->implode(', ');
            $lines[] = "- **{$slug}**: explicit {$row['explicit']}, inferred {$row['inferred']} (blocked: {$blocked})";
        }

        $lines[] = '';
        $lines[] = '## Explicit contradictions (curate before enforcement)';
        $lines[] = '';

        foreach ($report['contradictions'] as $slug => $row) {
            $lines[] = "- **{$slug}**: {$row['contradiction_total']} of {$row['explicit_total']} explicit rows";

            foreach ($row['contradictions'] as $kind => $items) {
                foreach ($items as $item) {
                    $lines[] = "  - {$kind}: #{$item['id']} {$item['name']}";
                }
            }

            foreach ($row['explicit_child_exclusive_review_only'] as $item) {
                $lines[] = "  - child_exclusive (review only): #{$item['id']} {$item['name']}";
            }
        }

        $lines[] = '';
        $lines[] = '## URL matrix: before vs after';
        $lines[] = '';
        $lines[] = '| State | Before | After | Delta | Listing unchanged | Facets changed | Top-12 incompatible (before / after) | Consistent |';
        $lines[] = '|---|---|---|---|---|---|---|---|';

        foreach ($report['matrix'] as $label => $row) {
            $consistent = collect($row['consistency'])->every(fn ($value): bool => $value === true) ? 'yes' : 'NO';
            $lines[] = sprintf(
                '| %s%s | %d | %d | %+d | %s | %s | %s / %s | %s |',
                $label,
                $row['control'] ? ' (control)' : '',
                $row['strict_total'],
                $row['eligible_total'],
                $row['delta'],
                $row['listing_unchanged'] ? 'yes' : 'no',
                $row['facet_dimensions_changed'] === [] ? '-' : implode(', ', $row['facet_dimensions_changed']),
                $row['strict_top12']['incompatible'],
                $row['eligible_top12']['incompatible'],
                $consistent,
            );
        }

        $lines[] = '';
        $lines[] = '## Finder replays: before vs after';
        $lines[] = '';
        $lines[] = '| Scenario | Top-12 identical | Added | Removed | Reordered only | Interest stage -/+ | Incompatible (before / after) |';
        $lines[] = '|---|---|---|---|---|---|---|';

        foreach ($report['finder'] as $label => $row) {
            $lines[] = sprintf(
                '| %s | %s | %d | %d | %s | %d / %d | %d / %d |',
                $label,
                $row['top12_identical'] ? 'yes' : 'no',
                count($row['top12_added']),
                count($row['top12_removed']),
                $row['same_ids_reordered'] ? 'yes' : 'no',
                count($row['interest_stage_removed']),
                count($row['interest_stage_added']),
                $row['strict_top12_incompatible'],
                $row['eligible_top12_incompatible'],
            );
        }

        if ($report['pool'] !== []) {
            $lines[] = '';
            $lines[] = '## Candidate pool benchmark';

            foreach ($report['pool'] as $label => $rows) {
                $lines[] = '';
                $lines[] = "### {$label}";
                $lines[] = '';
                $lines[] = '| Pool | Ordering | Truncated | Eligible | Top-12 kept | Top-24 kept | Pool covers ref top-24 | Median ms |';
                $lines[] = '|---|---|---|---|---|---|---|---|';

                foreach ($rows as $row) {
                    $lines[] = sprintf(
                        '| %s | %s | %s | %d | %d/12 | %d/24 | %s | %s |',
                        $row['pool_size'],
                        $row['ordering'],
                        $row['truncated'] ? 'yes' : 'no',
                        $row['eligible_total'],
                        $row['top12_retained'],
                        $row['top24_retained'],
                        $row['pool_coverage_of_reference_top24'] ?? 'n/a',
                        $row['median_ms'],
                    );
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<array{label: string, surface: string, slug: string|null, query: array<string, string>, control: bool}>
     */
    private function poolStates(): array
    {
        $wanted = [
            'relationship:husband?occasion=birthday&interest=fitness',
            'relationship:husband',
            'occasion:birthday?relationship=husband',
            'gift_ideas?relationship=wife',
        ];

        return collect(AuditStateMatrix::states())
            ->filter(fn (array $state): bool => in_array($state['label'], $wanted, true))
            ->values()
            ->all();
    }

    /**
     * Top-24 of the recommended listing under a pool size and ordering (0 = uncapped).
     *
     * @return array{ids: list<int>, total: int}
     */
    private function rankedIds(DiscoveryListingContext $context, DiscoveryListingQueryState $state, int $poolSize, bool $relevance): array
    {
        return $this->withConfig($this->poolOverrides($poolSize, $relevance), function () use ($context, $state): array {
            $paginator = $this->queryListing->execute($context, $state, 1, 2);

            return [
                'ids' => $paginator->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'total' => $paginator->total(),
            ];
        });
    }

    /**
     * How much of the reference top 24 survives into the bounded pool, before ranking.
     *
     * @param  list<int>  $referenceIds
     */
    private function poolCoverage(DiscoveryListingContext $context, DiscoveryListingQueryState $state, int $poolSize, bool $relevance, array $referenceIds): ?string
    {
        if ($poolSize === 0 || $referenceIds === []) {
            return null;
        }

        return $this->withConfig($this->poolOverrides($poolSize, $relevance), function () use ($context, $state, $poolSize, $relevance, $referenceIds): string {
            $filters = $state->toProductFilters($context);
            $eligibility = RecipientEligibilityConfig::context($context->surface);
            $query = $this->queryProducts->execute(
                $filters,
                config('discovery_ranking.listing_require_active_affiliate') === true,
                false,
                true,
                $eligibility,
            );

            if ($relevance) {
                $this->orderPool->execute($query, new DiscoveryRankingContext($context->surface, $filters, true, false, $eligibility));
            } else {
                $query->orderByDesc('products.published_at')->orderBy('products.id');
            }

            $pool = $query->limit($poolSize)->pluck('products.id')->map(fn ($id): int => (int) $id)->all();

            return count(array_intersect($pool, $referenceIds)).'/'.count($referenceIds);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function poolOverrides(int $poolSize, bool $relevance): array
    {
        return array_merge($this->eligibleOverrides(), [
            'discovery_ranking.candidate_pool_max' => $poolSize === 0 ? 1_000_000 : $poolSize,
            'recipient_eligibility.pool_ordering' => $relevance,
        ]);
    }

    /**
     * @param  list<int>  $ids
     * @return array{ids: list<int>, incompatible: int, incompatible_ids: list<int>, child_exclusive_ids: list<int>, explicit: int, inferred: int}
     */
    private function describeTop(array $ids, ?int $relationshipId): array
    {
        $guards = $relationshipId !== null ? $this->resolveGuards->for($relationshipId) : null;
        $contradictory = [];
        $childExclusive = [];
        $explicit = [];

        if ($ids !== [] && $guards !== null && RecipientEligibilityConfig::isGuarded($guards->slug)) {
            $contradictory = $this->idsMatching($ids, fn (Builder $q) => $this->constrain->whereContradiction($q, $guards), $this->constrain->hasContradictionRules($guards));
            $childExclusive = $guards->childOrientedRelationship || $guards->childTypeIds === []
                ? []
                : $this->idsMatching($ids, fn (Builder $q) => $this->constrain->whereChildExclusive($q, $guards));
            $explicit = $this->idsMatching($ids, fn (Builder $q) => $this->constrain->whereExplicit($q, $guards->relationshipId));
        }

        $incompatible = array_values(array_unique([...$contradictory, ...array_diff($childExclusive, $explicit)]));

        return [
            'ids' => $ids,
            'incompatible' => count($incompatible),
            'incompatible_ids' => $incompatible,
            'child_exclusive_ids' => $childExclusive,
            'explicit' => count($explicit),
            'inferred' => $guards !== null ? count($ids) - count($explicit) : 0,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function idsMatching(array $ids, Closure $condition, bool $applicable = true): array
    {
        if (! $applicable) {
            return [];
        }

        return Product::query()
            ->whereIn('products.id', $ids)
            ->where(fn (Builder $q) => $condition($q))
            ->pluck('products.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array{label: string, surface: string, slug: string|null, query: array<string, string>, control: bool}  $state
     */
    private function stateRelationshipId(array $state): ?int
    {
        $slug = $state['surface'] === 'relationship' ? $state['slug'] : ($state['query']['relationship'] ?? null);

        return $slug === null ? null : Relationship::query()->where('slug', $slug)->value('id');
    }

    /**
     * @param  array<string, array<string, int>>  $strict
     * @param  array<string, array<string, int>>  $eligible
     * @return list<string>
     */
    private function changedFacetDimensions(array $strict, array $eligible): array
    {
        return collect(array_unique([...array_keys($strict), ...array_keys($eligible)]))
            ->filter(fn (string $dimension): bool => ($strict[$dimension] ?? null) !== ($eligible[$dimension] ?? null))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $strict
     * @param  array<string, mixed>  $eligible
     */
    private function sameResults(array $strict, array $eligible): bool
    {
        return $strict['total'] === $eligible['total']
            && $strict['sorts'] === $eligible['sorts']
            && $strict['facets'] === $eligible['facets'];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function stratify(Collection $products, int $size): Collection
    {
        $groups = $products->groupBy(fn (Product $product): string => $this->primaryCategory($product))->sortKeys()->map->values();
        $picked = collect();
        $index = 0;

        while ($picked->count() < min($size, $products->count())) {
            foreach ($groups as $group) {
                if (isset($group[$index]) && $picked->count() < $size) {
                    $picked->push($group[$index]);
                }
            }

            $index++;
        }

        return $picked->sortBy('id')->values();
    }

    private function primaryCategory(Product $product): string
    {
        $category = $product->categories->first(fn ($category): bool => (bool) $category->pivot?->is_primary)
            ?? $product->categories->first();

        return $category?->full_path ?? 'uncategorised';
    }

    /**
     * @return array<int, RelationshipGuards>
     */
    private function waveGuards(): array
    {
        $ids = Relationship::query()
            ->whereIn('slug', RecipientEligibilityConfig::waveOneSlugs())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values($this->resolveGuards->execute($ids));
    }

    private function basePublished(): Builder
    {
        return Product::query()
            ->published()
            ->whereHas('affiliateLinks', fn (Builder $q) => $q->where('status', AffiliateLinkStatus::Active));
    }

    private function topN(): int
    {
        return (int) config('recipient_eligibility.audit.top_n', 12);
    }

    /**
     * @return array<string, mixed>
     */
    private function strictOverrides(): array
    {
        return ['recipient_eligibility.enabled' => false];
    }

    /**
     * Wave 1 fully on, everything else as configured. In memory only.
     *
     * @return array<string, mixed>
     */
    private function eligibleOverrides(): array
    {
        $relationships = (array) config('recipient_eligibility.relationships', []);

        foreach (RecipientEligibilityConfig::waveOneSlugs() as $slug) {
            $relationships[$slug]['infer'] = true;
        }

        return [
            'recipient_eligibility.enabled' => true,
            'recipient_eligibility.review_gate.required' => false,
            'recipient_eligibility.relationships' => $relationships,
            'recipient_eligibility.explicit_contradictions' => 'enforce',
            'recipient_eligibility.pool_ordering' => true,
            'recipient_eligibility.surfaces' => [
                'relationship' => 'inferred',
                'occasion' => 'inferred',
                'category' => 'inferred',
                'interest' => 'inferred',
                'gift_type' => 'inferred',
                'profession' => 'inferred',
                'gift_ideas' => 'inferred',
                'recipient_type' => 'off',
                'finder' => 'inferred',
            ],
            'recipient_eligibility.finder_audience' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  Closure(): mixed  $callback
     */
    private function withConfig(array $overrides, Closure $callback): mixed
    {
        $previous = [];

        foreach ($overrides as $key => $value) {
            $previous[$key] = config($key);
            config([$key => $value]);
        }

        try {
            return $callback();
        } finally {
            config($previous);
        }
    }
}
