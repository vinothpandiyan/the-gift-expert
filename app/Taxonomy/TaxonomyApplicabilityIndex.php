<?php

namespace App\Taxonomy;

use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Models\TaxonomyApplicabilityRule;
use Illuminate\Support\Collection;

/**
 * Pairwise 21F.3 applicability semantics for product-level classification.
 *
 * This is not the discovery listing option resolver. It uses the same rule
 * table and the same EXCLUDE/ALLOW fail-closed meaning.
 */
class TaxonomyApplicabilityIndex
{
    /**
     * @param  array<string, true>  $excludeKeys
     * @param  array<string, array<string, list<int>>>  $allowTargets
     * @param  array<string, array<string, true>>  $allowRestricted
     */
    public function __construct(
        private array $excludeKeys,
        private array $allowTargets,
        private array $allowRestricted,
    ) {}

    public static function load(): self
    {
        $rules = TaxonomyApplicabilityRule::query()
            ->where('is_active', true)
            ->get();

        $idsByDimension = [];

        foreach ($rules as $rule) {
            $idsByDimension[$rule->sourceDimension()->value][] = (int) $rule->source_id;
            $idsByDimension[$rule->targetDimension()->value][] = (int) $rule->target_id;
        }

        $activeIds = [];

        foreach ($idsByDimension as $dimensionValue => $ids) {
            $activeIds[$dimensionValue] = TaxonomyDimension::from($dimensionValue)->activeIds($ids);
        }

        [$excludeKeys, $allowTargets, $allowRestricted] = self::indexActiveRules($rules, $activeIds);

        return new self($excludeKeys, $allowTargets, $allowRestricted);
    }

    public function pairIsApplicable(
        TaxonomyDimension $leftDimension,
        int $leftId,
        TaxonomyDimension $rightDimension,
        int $rightId,
    ): bool {
        if ($leftDimension === $rightDimension && $leftId === $rightId) {
            return true;
        }

        if ($leftDimension->activeIds([$leftId]) === [] || $rightDimension->activeIds([$rightId]) === []) {
            return true;
        }

        $pairKey = TaxonomyApplicabilityRule::canonicalKey(
            $leftDimension,
            $leftId,
            $rightDimension,
            $rightId,
        );

        if (isset($this->excludeKeys[$pairKey])) {
            return false;
        }

        $leftKey = $leftDimension->value.':'.$leftId;
        $leftIsRestricted = $this->allowRestricted[$leftKey][$rightDimension->value] ?? false;
        $leftAllowTargets = $this->allowTargets[$leftKey][$rightDimension->value] ?? [];

        if ($leftIsRestricted && ! in_array($rightId, $leftAllowTargets, true)) {
            return false;
        }

        $rightKey = $rightDimension->value.':'.$rightId;
        $rightIsRestricted = $this->allowRestricted[$rightKey][$leftDimension->value] ?? false;
        $rightAllowTargets = $this->allowTargets[$rightKey][$leftDimension->value] ?? [];

        if ($rightIsRestricted && ! in_array($leftId, $rightAllowTargets, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, TaxonomyApplicabilityRule>  $rules
     * @param  array<string, list<int>>  $activeIds
     * @return array{0: array<string, true>, 1: array<string, array<string, list<int>>>, 2: array<string, array<string, true>>}
     */
    private static function indexActiveRules(Collection $rules, array $activeIds): array
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
                if (! self::idIsActive($sourceDimension, $sourceId, $activeIds)
                    || ! self::idIsActive($targetDimension, $targetId, $activeIds)) {
                    continue;
                }

                $excludeKeys[$rule->canonical_key] = true;

                continue;
            }

            if (! self::idIsActive($sourceDimension, $sourceId, $activeIds)) {
                continue;
            }

            $sourceKey = $sourceDimension->value.':'.$sourceId;
            $allowRestricted[$sourceKey][$targetDimension->value] = true;

            if (! self::idIsActive($targetDimension, $targetId, $activeIds)) {
                continue;
            }

            $allowTargets[$sourceKey][$targetDimension->value][] = $targetId;
        }

        return [$excludeKeys, $allowTargets, $allowRestricted];
    }

    /**
     * @param  array<string, list<int>>  $activeIds
     */
    private static function idIsActive(TaxonomyDimension $dimension, int $id, array $activeIds): bool
    {
        return in_array($id, $activeIds[$dimension->value] ?? [], true);
    }
}
