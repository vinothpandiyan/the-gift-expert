<?php

namespace App\Actions\Taxonomy;

use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Models\TaxonomyApplicabilityRule;
use Illuminate\Support\Collection;

/**
 * Semantic taxonomy applicability for discovery filters.
 *
 * Default: a candidate is allowed unless an explicit restriction applies.
 *
 * Precedence for one context value vs one candidate value:
 *   1. Explicit EXCLUDE for the unordered pair (either stored orientation) → excluded
 *   2. ALLOW-restricted value: if a stored ALLOW rule uses a value as *source* for the
 *      other dimension, non-listed counterparts of that dimension are excluded
 *   3. Default ALLOW
 *
 * EXCLUDE is bidirectional: Husband EXCLUDE Baby Shower hides Baby Shower on the
 * Husband page and Husband on the Baby Shower page without a second row.
 *
 * ALLOW is directional. The *source* is the restricted value (e.g. Raksha Bandhan).
 * Targets are the permitted counterparts (Brother, Sister). That does not restrict
 * Brother to Raksha Bandhan only.
 *
 * An active ALLOW-restricted source stays restricted even when some or all of its
 * allowed targets are inactive or missing. That is fail-closed: Raksha Bandhan does
 * not become valid for Husband merely because Brother and Sister were deactivated.
 *
 * Multiple contexts: a candidate is applicable only if no active context excludes it.
 *
 * Inactive rules and inactive/deleted taxonomy rows are ignored. This action does
 * not consult product counts.
 */
class ResolveApplicableTaxonomyOptionsAction
{
    /**
     * @param  iterable<int|object>  $candidates
     * @return list<int>
     */
    public function execute(
        DiscoveryListingContext $listing,
        TaxonomyDimension $candidateDimension,
        iterable $candidates,
        ?DiscoveryListingQueryState $queryState = null,
    ): array {
        $candidateIds = $this->normalizeCandidateIds($candidates);

        if ($candidateIds === []) {
            return [];
        }

        $filters = $queryState === null
            ? $listing->fixedFilters
            : $queryState->toProductFilters($listing);

        $contexts = TaxonomyDimension::contextsFromFilters($filters);
        $rules = TaxonomyApplicabilityRule::query()
            ->where('is_active', true)
            ->get();

        $idsByDimension = $this->collectIdsByDimension($candidateDimension, $candidateIds, $contexts, $rules);
        $activeIds = $this->loadActiveIds($idsByDimension);
        [$excludeKeys, $allowTargets, $allowRestricted] = $this->indexActiveRules($rules, $activeIds);

        $activeContexts = [];

        foreach ($contexts as $context) {
            if ($this->isActive($context['dimension'], $context['id'], $activeIds)) {
                $activeContexts[] = $context;
            }
        }

        $applicable = [];

        foreach ($candidateIds as $candidateId) {
            if (! $this->isActive($candidateDimension, $candidateId, $activeIds)) {
                continue;
            }

            if ($this->isApplicable($candidateDimension, $candidateId, $activeContexts, $excludeKeys, $allowTargets, $allowRestricted)) {
                $applicable[] = $candidateId;
            }
        }

        return $applicable;
    }

    /**
     * @param  iterable<int|object>  $candidates
     * @return list<int>
     */
    private function normalizeCandidateIds(iterable $candidates): array
    {
        $ids = [];

        foreach ($candidates as $candidate) {
            $id = is_object($candidate) ? (int) $candidate->id : (int) $candidate;

            if ($id < 1 || in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param  list<int>  $candidateIds
     * @param  list<array{dimension: TaxonomyDimension, id: int}>  $contexts
     * @param  Collection<int, TaxonomyApplicabilityRule>  $rules
     * @return array<string, list<int>>
     */
    private function collectIdsByDimension(
        TaxonomyDimension $candidateDimension,
        array $candidateIds,
        array $contexts,
        Collection $rules,
    ): array {
        $idsByDimension = [];

        $add = function (TaxonomyDimension $dimension, int $id) use (&$idsByDimension): void {
            $idsByDimension[$dimension->value][] = $id;
        };

        foreach ($candidateIds as $id) {
            $add($candidateDimension, $id);
        }

        foreach ($contexts as $context) {
            $add($context['dimension'], $context['id']);
        }

        foreach ($rules as $rule) {
            $add($rule->sourceDimension(), (int) $rule->source_id);
            $add($rule->targetDimension(), (int) $rule->target_id);
        }

        return $idsByDimension;
    }

    /**
     * @param  array<string, list<int>>  $idsByDimension
     * @return array<string, list<int>>
     */
    private function loadActiveIds(array $idsByDimension): array
    {
        $active = [];

        foreach ($idsByDimension as $dimensionValue => $ids) {
            $dimension = TaxonomyDimension::from($dimensionValue);
            $active[$dimensionValue] = $dimension->activeIds($ids);
        }

        return $active;
    }

    /**
     * @param  Collection<int, TaxonomyApplicabilityRule>  $rules
     * @param  array<string, list<int>>  $activeIds
     * @return array{0: array<string, true>, 1: array<string, array<string, list<int>>>, 2: array<string, array<string, true>>}
     */
    private function indexActiveRules(Collection $rules, array $activeIds): array
    {
        $excludeKeys = [];
        $allowTargets = [];
        $allowRestricted = [];

        foreach ($rules as $rule) {
            $sourceDimension = $rule->sourceDimension();
            $targetDimension = $rule->targetDimension();
            $sourceId = (int) $rule->source_id;
            $targetId = (int) $rule->target_id;

            if ($rule->effect === TaxonomyApplicabilityEffect::Exclude) {
                if (! $this->isActive($sourceDimension, $sourceId, $activeIds)
                    || ! $this->isActive($targetDimension, $targetId, $activeIds)) {
                    continue;
                }

                $excludeKeys[$rule->canonical_key] = true;

                continue;
            }

            if (! $this->isActive($sourceDimension, $sourceId, $activeIds)) {
                continue;
            }

            $sourceKey = $sourceDimension->value.':'.$sourceId;
            $allowRestricted[$sourceKey][$targetDimension->value] = true;

            if (! $this->isActive($targetDimension, $targetId, $activeIds)) {
                continue;
            }

            $allowTargets[$sourceKey][$targetDimension->value][] = $targetId;
        }

        return [$excludeKeys, $allowTargets, $allowRestricted];
    }

    /**
     * @param  list<array{dimension: TaxonomyDimension, id: int}>  $contexts
     * @param  array<string, true>  $excludeKeys
     * @param  array<string, array<string, list<int>>>  $allowTargets
     * @param  array<string, array<string, true>>  $allowRestricted
     */
    private function isApplicable(
        TaxonomyDimension $candidateDimension,
        int $candidateId,
        array $contexts,
        array $excludeKeys,
        array $allowTargets,
        array $allowRestricted,
    ): bool {
        foreach ($contexts as $context) {
            if ($context['dimension'] === $candidateDimension && $context['id'] === $candidateId) {
                continue;
            }

            if (! $this->contextAllowsCandidate(
                $context['dimension'],
                $context['id'],
                $candidateDimension,
                $candidateId,
                $excludeKeys,
                $allowTargets,
                $allowRestricted,
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, true>  $excludeKeys
     * @param  array<string, array<string, list<int>>>  $allowTargets
     * @param  array<string, array<string, true>>  $allowRestricted
     */
    private function contextAllowsCandidate(
        TaxonomyDimension $contextDimension,
        int $contextId,
        TaxonomyDimension $candidateDimension,
        int $candidateId,
        array $excludeKeys,
        array $allowTargets,
        array $allowRestricted,
    ): bool {
        $pairKey = TaxonomyApplicabilityRule::canonicalKey(
            $contextDimension,
            $contextId,
            $candidateDimension,
            $candidateId,
        );

        if (isset($excludeKeys[$pairKey])) {
            return false;
        }

        $candidateKey = $candidateDimension->value.':'.$candidateId;
        $candidateIsRestricted = $allowRestricted[$candidateKey][$contextDimension->value] ?? false;
        $candidateAllowTargets = $allowTargets[$candidateKey][$contextDimension->value] ?? [];

        if ($candidateIsRestricted && ! in_array($contextId, $candidateAllowTargets, true)) {
            return false;
        }

        $contextKey = $contextDimension->value.':'.$contextId;
        $contextIsRestricted = $allowRestricted[$contextKey][$candidateDimension->value] ?? false;
        $contextAllowTargets = $allowTargets[$contextKey][$candidateDimension->value] ?? [];

        if ($contextIsRestricted && ! in_array($candidateId, $contextAllowTargets, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, list<int>>  $activeIds
     */
    private function isActive(TaxonomyDimension $dimension, int $id, array $activeIds): bool
    {
        return in_array($id, $activeIds[$dimension->value] ?? [], true);
    }
}
