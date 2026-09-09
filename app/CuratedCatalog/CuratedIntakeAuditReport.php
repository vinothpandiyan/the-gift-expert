<?php

namespace App\CuratedCatalog;

readonly class CuratedIntakeAuditReport
{
    /**
     * @param  array<string, int>  $classificationCounts
     * @param  array<string, int>  $publicationCounts
     * @param  array<string, int>  $commercialConflictFieldCounts
     * @param  list<array<string, mixed>>  $missingPrimaryCategory
     * @param  list<array<string, mixed>>  $multiplePrimaryCategories
     * @param  list<array<string, mixed>>  $missingAncestors
     * @param  list<array<string, mixed>>  $semanticConflicts
     * @param  list<array<string, mixed>>  $provenanceIssues
     * @param  list<array<string, mixed>>  $residualNone
     * @param  list<array<string, mixed>>  $reviewPriority
     * @param  list<array<string, mixed>>  $spotCheck
     * @param  list<array<string, mixed>>  $inactiveHintRelationships
     * @param  list<array<string, mixed>>  $taxonomyFromNonHintLists
     */
    public function __construct(
        public int $intakeRunId,
        public int $uniqueProducts,
        public int $newProducts,
        public int $existingProducts,
        public int $multiListProducts,
        public int $provenanceRows,
        public int $wrongMerchantProvenance,
        public array $classificationCounts,
        public array $publicationCounts,
        public array $commercialConflictFieldCounts,
        public array $missingPrimaryCategory,
        public array $multiplePrimaryCategories,
        public array $missingAncestors,
        public array $semanticConflicts,
        public array $provenanceIssues,
        public array $residualNone,
        public array $reviewPriority,
        public array $spotCheck,
        public array $inactiveHintRelationships,
        public array $taxonomyFromNonHintLists,
        public int $reviewCount,
        public int $failedCount,
        public int $aiAcceptedCount,
    ) {}
}
