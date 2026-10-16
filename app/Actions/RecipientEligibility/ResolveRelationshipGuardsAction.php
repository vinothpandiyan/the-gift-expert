<?php

namespace App\Actions\RecipientEligibility;

use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Models\GiftType;
use App\Models\Occasion;
use App\Models\RecipientGender;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\TaxonomyApplicabilityRule;
use App\RecipientEligibility\RecipientEligibilityConfig;
use App\RecipientEligibility\RelationshipGuards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves guard inputs for relationships with a constant number of queries.
 *
 * Pair applicability mirrors TaxonomyApplicabilityIndex (EXCLUDE pairs and
 * fail-closed ALLOW lists, active endpoints only) but is computed set-wise so
 * the result can be compiled into a SQL predicate instead of being checked per
 * product or per pair.
 */
class ResolveRelationshipGuardsAction
{
    /** @var array<int, RelationshipGuards> */
    private array $memo = [];

    /**
     * @param  list<int>  $relationshipIds
     * @return array<int, RelationshipGuards>
     */
    public function execute(array $relationshipIds): array
    {
        $relationshipIds = array_values(array_unique(array_map('intval', $relationshipIds)));
        $missing = array_values(array_diff($relationshipIds, array_keys($this->memo)));

        if ($missing !== []) {
            $this->load($missing);
        }

        return collect($relationshipIds)
            ->filter(fn (int $id): bool => isset($this->memo[$id]))
            ->mapWithKeys(fn (int $id): array => [$id => $this->memo[$id]])
            ->all();
    }

    public function for(int $relationshipId): ?RelationshipGuards
    {
        return $this->execute([$relationshipId])[$relationshipId] ?? null;
    }

    /**
     * @param  list<int>  $ids
     */
    private function load(array $ids): void
    {
        $slugs = Relationship::query()->whereIn('id', $ids)->pluck('slug', 'id');

        if ($slugs->isEmpty()) {
            return;
        }

        $genderIds = RecipientGender::query()->where('is_active', true)->pluck('id', 'slug');
        $childTypeIds = RecipientType::query()
            ->whereIn('slug', (array) config('recipient_eligibility.child_audience_types', []))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
        $activeOccasionIds = $this->activeIds(Occasion::query());
        $activeGiftTypeIds = $this->activeIds(GiftType::query());
        $activeRelationshipIds = $this->activeIds(Relationship::query());
        $rules = TaxonomyApplicabilityRule::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('source_dimension', TaxonomyDimension::Relationship->value)
                    ->orWhere('target_dimension', TaxonomyDimension::Relationship->value);
            })
            ->get();

        foreach ($slugs as $id => $slug) {
            $id = (int) $id;
            $slug = (string) $slug;
            $inapplicableOccasions = $this->inapplicableIds($rules, $id, TaxonomyDimension::Occasion, $activeOccasionIds, $activeRelationshipIds);
            $excludedGiftTypes = $this->inapplicableIds($rules, $id, TaxonomyDimension::GiftType, $activeGiftTypeIds, $activeRelationshipIds);

            $impliedGender = RecipientEligibilityConfig::impliedGenderSlug($slug);
            $acceptable = null;

            if ($impliedGender !== null && isset($genderIds[$impliedGender])) {
                $acceptable = collect([$genderIds[$impliedGender], $genderIds[RecipientGender::SLUG_UNISEX] ?? null])
                    ->filter()
                    ->map(fn ($genderId): int => (int) $genderId)
                    ->unique()
                    ->values()
                    ->all();
            }

            $this->memo[$id] = new RelationshipGuards(
                relationshipId: $id,
                slug: $slug,
                acceptableGenderIds: $acceptable,
                childTypeIds: $childTypeIds,
                excludedGiftTypeIds: $excludedGiftTypes,
                applicableOccasionIds: array_values(array_diff($activeOccasionIds, $inapplicableOccasions)),
                inapplicableOccasionIds: $inapplicableOccasions,
                blockedCategoryPaths: array_values(array_map('strval', (array) config('recipient_eligibility.inference_blocked_category_paths', []))),
                childOrientedRelationship: in_array($slug, (array) config('recipient_eligibility.child_oriented_relationships', []), true),
            );
        }
    }

    /**
     * @param  Collection<int, TaxonomyApplicabilityRule>  $rules
     * @param  list<int>  $activeDimensionIds
     * @param  list<int>  $activeRelationshipIds
     * @return list<int>
     */
    private function inapplicableIds(
        Collection $rules,
        int $relationshipId,
        TaxonomyDimension $dimension,
        array $activeDimensionIds,
        array $activeRelationshipIds,
    ): array {
        $inapplicable = [];
        $relationshipRestricted = false;
        $relationshipAllowed = [];
        $dimensionTargets = [];

        foreach ($rules as $rule) {
            $relationshipIsSource = $rule->source_dimension === TaxonomyDimension::Relationship
                && (int) $rule->source_id === $relationshipId
                && $rule->target_dimension === $dimension;
            $relationshipIsTarget = $rule->target_dimension === TaxonomyDimension::Relationship
                && (int) $rule->target_id === $relationshipId
                && $rule->source_dimension === $dimension;

            if ($rule->effect === TaxonomyApplicabilityEffect::Exclude) {
                if ($relationshipIsSource) {
                    $inapplicable[] = (int) $rule->target_id;
                } elseif ($relationshipIsTarget) {
                    $inapplicable[] = (int) $rule->source_id;
                }

                continue;
            }

            if ($relationshipIsSource) {
                $relationshipRestricted = true;
                $relationshipAllowed[] = (int) $rule->target_id;
            }

            if ($rule->source_dimension === $dimension && $rule->target_dimension === TaxonomyDimension::Relationship) {
                $dimensionTargets[(int) $rule->source_id] ??= [];

                if (in_array((int) $rule->target_id, $activeRelationshipIds, true)) {
                    $dimensionTargets[(int) $rule->source_id][] = (int) $rule->target_id;
                }
            }
        }

        if ($relationshipRestricted) {
            foreach ($activeDimensionIds as $dimensionId) {
                if (! in_array($dimensionId, $relationshipAllowed, true)) {
                    $inapplicable[] = $dimensionId;
                }
            }
        }

        foreach ($dimensionTargets as $dimensionId => $targets) {
            if (! in_array($relationshipId, $targets, true)) {
                $inapplicable[] = $dimensionId;
            }
        }

        return array_values(array_intersect(array_unique($inapplicable), $activeDimensionIds));
    }

    /**
     * @return list<int>
     */
    private function activeIds(Builder $query): array
    {
        return $query->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
