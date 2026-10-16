<?php

namespace App\Actions\RecipientEligibility;

use App\RecipientEligibility\RecipientEligibilityConfig;
use App\RecipientEligibility\RecipientEligibilityContext;
use App\RecipientEligibility\RelationshipGuards;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Compiles recipient eligibility into SQL predicates on the products query:
 *
 *   relationship constraint = explicit pivot (minus known contradictions, when enforced)
 *                             OR inferred eligibility (opt-in, per relationship)
 *
 * Inferred eligibility never writes pivots. A product is inferred for a
 * relationship only when it carries no relationship pivot at all and passes
 * every guard: gender evidence, not exclusively child-oriented, category,
 * gift type and occasion applicability.
 *
 * Missing data blocks inference but never counts as a contradiction.
 */
class ConstrainProductsByRecipientEligibilityAction
{
    /**
     * Guard reasons, in funnel order. Each closure adds a condition that is true
     * when the product is blocked from inference for that reason.
     */
    public const REASON_RECIPIENT_SIGNALLED = 'recipient_signalled';

    public const REASON_GENDER_INCOMPATIBLE = 'gender_incompatible';

    public const REASON_GENDER_MISSING = 'gender_missing';

    public const REASON_CHILD_AUDIENCE = 'child_audience';

    public const REASON_CATEGORY_GUARD = 'category_guard';

    public const REASON_GIFT_TYPE_EXCLUDED = 'gift_type_excluded';

    public const REASON_NO_APPLICABLE_OCCASION = 'no_applicable_occasion';

    public function __construct(
        private ResolveRelationshipGuardsAction $resolveGuards,
    ) {}

    /**
     * Replaces the strict "has any of these relationships" constraint.
     *
     * @param  list<int>  $relationshipIds
     */
    public function constrainRelationships(Builder $query, array $relationshipIds, RecipientEligibilityContext $context): void
    {
        $guards = $this->resolveGuards->execute($relationshipIds);

        $query->where(function (Builder $outer) use ($relationshipIds, $guards, $context): void {
            foreach ($relationshipIds as $relationshipId) {
                $relationshipGuards = $guards[$relationshipId] ?? null;

                $outer->orWhere(function (Builder $branch) use ($relationshipId, $relationshipGuards, $context): void {
                    $this->wherePredicate($branch, $relationshipId, $relationshipGuards, $context);
                });
            }
        });
    }

    /**
     * Predicate for one relationship: explicit (optionally minus contradictions)
     * or inferred. Used for listings, facets and the audit.
     */
    public function wherePredicate(Builder $query, int $relationshipId, ?RelationshipGuards $guards, RecipientEligibilityContext $context): void
    {
        $slug = $guards?->slug;
        $guarded = $slug !== null && RecipientEligibilityConfig::isGuarded($slug);
        $infers = $guards !== null && $slug !== null && $guarded && $context->infers($slug);
        $dropContradictions = $guards !== null && $guarded && $context->enforceExplicitContradictions;

        $query->where(function (Builder $explicit) use ($relationshipId, $guards, $dropContradictions): void {
            $explicit->whereExists($this->explicitSubquery($relationshipId));

            if ($dropContradictions && $guards !== null) {
                $this->whereNotContradiction($explicit, $guards);
            }
        });

        if ($infers) {
            $query->orWhere(function (Builder $inferred) use ($guards): void {
                $this->whereInferred($inferred, $guards);
            });
        }
    }

    /**
     * Excludes products with a known contradiction against the relationship.
     * Explicit rows are only affected when `$includeExplicit` is true.
     */
    public function excludeContradictions(Builder $query, int $relationshipId, bool $includeExplicit): void
    {
        $guards = $this->resolveGuards->for($relationshipId);

        if ($guards === null || ! RecipientEligibilityConfig::isGuarded($guards->slug)) {
            return;
        }

        if (! $this->hasContradictionRules($guards)) {
            return;
        }

        $query->whereNot(function (Builder $contradictory) use ($guards, $relationshipId, $includeExplicit): void {
            $contradictory->where(function (Builder $inner) use ($guards): void {
                $this->whereContradiction($inner, $guards);
            });

            if (! $includeExplicit) {
                $contradictory->whereNotExists($this->explicitSubquery($relationshipId));
            }
        });
    }

    public const CONTRADICTION_GENDER_MISMATCH = 'gender_mismatch';

    public const CONTRADICTION_GIFT_TYPE_EXCLUDED = 'gift_type_excluded';

    public const CONTRADICTION_NO_APPLICABLE_OCCASION = 'no_applicable_occasion';

    /**
     * Known contradiction: product evidence contradicts the relationship.
     */
    public function whereContradiction(Builder $query, RelationshipGuards $guards): void
    {
        foreach ($this->contradictionKinds($guards) as $kind) {
            $query->orWhere(function (Builder $branch) use ($kind): void {
                $kind($branch);
            });
        }
    }

    /**
     * Each kind of known contradiction as its own predicate (for reports).
     *
     * @return array<string, Closure(Builder): void>
     */
    public function contradictionKinds(RelationshipGuards $guards): array
    {
        $kinds = [];

        if ($guards->acceptableGenderIds !== null) {
            $kinds[self::CONTRADICTION_GENDER_MISMATCH] = fn (Builder $q) => $this->whereGenderIncompatible($q, $guards);
        }

        if ($guards->excludedGiftTypeIds !== []) {
            $kinds[self::CONTRADICTION_GIFT_TYPE_EXCLUDED] = fn (Builder $q) => $q->whereExists(
                fn ($sub) => $sub->selectRaw('1')
                    ->from('gift_type_product')
                    ->whereColumn('gift_type_product.product_id', 'products.id')
                    ->whereIn('gift_type_product.gift_type_id', $guards->excludedGiftTypeIds),
            );
        }

        if ($guards->inapplicableOccasionIds !== []) {
            $kinds[self::CONTRADICTION_NO_APPLICABLE_OCCASION] = fn (Builder $q) => $q
                ->whereExists($this->pivotExists('occasion_product'))
                ->whereNotExists(
                    fn ($sub) => $sub->selectRaw('1')
                        ->from('occasion_product')
                        ->whereColumn('occasion_product.product_id', 'products.id')
                        ->whereNotIn('occasion_product.occasion_id', $guards->inapplicableOccasionIds),
                );
        }

        return $kinds;
    }

    public function whereExplicit(Builder $query, int $relationshipId): void
    {
        $query->whereExists($this->explicitSubquery($relationshipId));
    }

    public function hasContradictionRules(RelationshipGuards $guards): bool
    {
        return $guards->acceptableGenderIds !== null
            || $guards->excludedGiftTypeIds !== []
            || $guards->inapplicableOccasionIds !== [];
    }

    /**
     * Named, ordered reasons that block inference. Each closure receives a builder
     * and adds a condition that is true when the product is blocked.
     *
     * @return array<string, Closure(Builder): void>
     */
    public function blockingReasons(RelationshipGuards $guards): array
    {
        $reasons = [
            self::REASON_RECIPIENT_SIGNALLED => fn (Builder $q) => $q->whereExists($this->pivotExists('relationship_product')),
        ];

        if ($guards->acceptableGenderIds !== null) {
            $reasons[self::REASON_GENDER_INCOMPATIBLE] = fn (Builder $q) => $this->whereGenderIncompatible($q, $guards);
            $reasons[self::REASON_GENDER_MISSING] = fn (Builder $q) => $q->whereNotExists($this->pivotExists('recipient_gender_product'));
        }

        if (! $guards->childOrientedRelationship && $guards->childTypeIds !== []) {
            $reasons[self::REASON_CHILD_AUDIENCE] = fn (Builder $q) => $this->whereChildExclusive($q, $guards);
        }

        if ($guards->blockedCategoryPaths !== []) {
            $reasons[self::REASON_CATEGORY_GUARD] = fn (Builder $q) => $q->whereExists(
                fn ($sub) => $sub->selectRaw('1')
                    ->from('category_product')
                    ->join('categories', 'categories.id', '=', 'category_product.category_id')
                    ->whereColumn('category_product.product_id', 'products.id')
                    ->where(function ($paths) use ($guards): void {
                        foreach ($guards->blockedCategoryPaths as $path) {
                            $paths->orWhere('categories.full_path', $path)
                                ->orWhere('categories.full_path', 'like', $path.'/%');
                        }
                    }),
            );
        }

        if ($guards->excludedGiftTypeIds !== []) {
            $reasons[self::REASON_GIFT_TYPE_EXCLUDED] = fn (Builder $q) => $q->whereExists(
                fn ($sub) => $sub->selectRaw('1')
                    ->from('gift_type_product')
                    ->whereColumn('gift_type_product.product_id', 'products.id')
                    ->whereIn('gift_type_product.gift_type_id', $guards->excludedGiftTypeIds),
            );
        }

        $reasons[self::REASON_NO_APPLICABLE_OCCASION] = function (Builder $q) use ($guards): void {
            if ($guards->applicableOccasionIds === []) {
                $q->whereRaw('1 = 1');

                return;
            }

            $q->whereNotExists(
                fn ($sub) => $sub->selectRaw('1')
                    ->from('occasion_product')
                    ->whereColumn('occasion_product.product_id', 'products.id')
                    ->whereIn('occasion_product.occasion_id', $guards->applicableOccasionIds),
            );
        };

        return $reasons;
    }

    /**
     * Exclusively child-oriented: has recipient types and every one is a child audience.
     */
    public function whereChildExclusive(Builder $query, RelationshipGuards $guards): void
    {
        $query->whereExists($this->pivotExists('recipient_type_product'))
            ->whereNotExists(
                fn ($sub) => $sub->selectRaw('1')
                    ->from('recipient_type_product')
                    ->whereColumn('recipient_type_product.product_id', 'products.id')
                    ->whereNotIn('recipient_type_product.recipient_type_id', $guards->childTypeIds),
            );
    }

    /**
     * Known gender mismatch: has gender rows, none is unisex or the implied gender.
     * A product without any gender row is never a mismatch.
     */
    public function whereGenderIncompatible(Builder $query, RelationshipGuards $guards): void
    {
        if ($guards->acceptableGenderIds === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereExists($this->pivotExists('recipient_gender_product'))
            ->whereNotExists(
                fn ($sub) => $sub->selectRaw('1')
                    ->from('recipient_gender_product')
                    ->whereColumn('recipient_gender_product.product_id', 'products.id')
                    ->whereIn('recipient_gender_product.recipient_gender_id', $guards->acceptableGenderIds),
            );
    }

    /**
     * Inferred eligibility: no blocking reason applies.
     */
    public function whereInferred(Builder $query, RelationshipGuards $guards): void
    {
        foreach ($this->blockingReasons($guards) as $reason) {
            $query->whereNot(function (Builder $blocked) use ($reason): void {
                $reason($blocked);
            });
        }
    }

    private function whereNotContradiction(Builder $query, RelationshipGuards $guards): void
    {
        if (! $this->hasContradictionRules($guards)) {
            return;
        }

        $query->whereNot(function (Builder $contradictory) use ($guards): void {
            $this->whereContradiction($contradictory, $guards);
        });
    }

    private function explicitSubquery(int $relationshipId): Closure
    {
        return fn ($sub) => $sub->selectRaw('1')
            ->from('relationship_product')
            ->whereColumn('relationship_product.product_id', 'products.id')
            ->where('relationship_product.relationship_id', $relationshipId);
    }

    private function pivotExists(string $table): Closure
    {
        return fn ($sub) => $sub->selectRaw('1')
            ->from($table)
            ->whereColumn($table.'.product_id', 'products.id');
    }
}
