<?php

namespace App\RecipientEligibility;

/**
 * Resolved guard inputs for one relationship. Built once per request from config,
 * recipient types, genders and the existing TaxonomyApplicabilityRule table.
 */
final class RelationshipGuards
{
    /**
     * @param  list<int>|null  $acceptableGenderIds  unisex + implied gender; null when the relationship implies no gender
     * @param  list<int>  $childTypeIds  recipient types counted as child audience
     * @param  list<int>  $excludedGiftTypeIds  gift types with an EXCLUDE/ALLOW-restricted rule for this relationship
     * @param  list<int>  $applicableOccasionIds  active occasions that apply to this relationship
     * @param  list<int>  $inapplicableOccasionIds  active occasions that do not apply to this relationship
     * @param  list<string>  $blockedCategoryPaths  category paths (and descendants) where inference is blocked
     */
    public function __construct(
        public readonly int $relationshipId,
        public readonly string $slug,
        public readonly ?array $acceptableGenderIds,
        public readonly array $childTypeIds,
        public readonly array $excludedGiftTypeIds,
        public readonly array $applicableOccasionIds,
        public readonly array $inapplicableOccasionIds,
        public readonly array $blockedCategoryPaths,
        public readonly bool $childOrientedRelationship,
    ) {}
}
