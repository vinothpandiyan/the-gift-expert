<?php

namespace App\CatalogCuration;

readonly class P2TaxonomyReview
{
    /**
     * @param  array<string, list<array{id: int, name: string, slug: string, strength: ?string, reason: ?string, misleading: ?bool}>>  $currentAssignments
     * @param  array<string, list<array{id: int, name: string, slug: string, strength: ?string, reason: ?string}>>  $suggestedAdditions
     * @param  list<array{dimension: string, name: string, severity: string, reason: string, cause: string}>  $materialFindings
     * @param  list<string>  $applicabilityConflicts
     */
    public function __construct(
        public ProductTaxonomySnapshot $snapshot,
        public array $currentAssignments,
        public array $suggestedAdditions,
        public array $materialFindings,
        public array $applicabilityConflicts,
        public bool $hasAuthoritativeConflict,
    ) {}
}
