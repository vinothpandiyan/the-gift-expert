<?php

namespace App\RecipientEligibility;

/**
 * Opt-in eligibility mode handed to QueryPublishedProductsByFiltersAction.
 * A null context always means strict, explicit-pivot behavior.
 */
final class RecipientEligibilityContext
{
    /**
     * @param  list<string>  $inferRelationshipSlugs  relationships that may be inferred on this surface
     * @param  bool  $enforceExplicitContradictions  drop explicit rows with a known contradiction
     */
    public function __construct(
        public readonly string $surface,
        public readonly array $inferRelationshipSlugs,
        public readonly bool $enforceExplicitContradictions,
    ) {}

    public function infersAny(): bool
    {
        return $this->inferRelationshipSlugs !== [];
    }

    public function infers(string $relationshipSlug): bool
    {
        return in_array($relationshipSlug, $this->inferRelationshipSlugs, true);
    }
}
