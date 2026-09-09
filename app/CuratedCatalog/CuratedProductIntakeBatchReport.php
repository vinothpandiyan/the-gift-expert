<?php

namespace App\CuratedCatalog;

readonly class CuratedProductIntakeBatchReport
{
    /**
     * @param  list<string>  $files
     * @param  list<array<string, mixed>>  $sourceLists
     * @param  list<array<string, mixed>>  $unmappedLists
     * @param  array<string, int>  $productsBySourceList
     * @param  array<string, int>  $productsByRelationshipHint
     * @param  list<array<string, mixed>>  $commercialConflictItems
     * @param  array<string, int>  $commercialConflictFieldCounts
     * @param  list<array<string, mixed>>  $duplicateIdentities
     * @param  list<array<string, mixed>>  $malformedSourceLists
     * @param  list<string>  $filesMissingSourceList
     */
    public function __construct(
        public string $merchantSlug,
        public array $files,
        public int $wishlistCount,
        public int $rawOccurrences,
        public int $uniqueProducts,
        public int $mergedOccurrences,
        public int $multiListProducts,
        public int $newProducts,
        public int $existingProducts,
        public int $recipientHintLists,
        public int $unclassifiedLists,
        public int $quarterlyArchiveLists,
        public int $unknownLists,
        public int $malformedExternalIds,
        public int $commercialConflicts,
        public int $multiRecipientProducts,
        public array $sourceLists,
        public array $unmappedLists,
        public array $productsBySourceList,
        public array $productsByRelationshipHint,
        public array $commercialConflictItems,
        public CuratedProductIntakePreview $preview,
        public bool $isDirectoryBatch = false,
        public array $commercialConflictFieldCounts = [],
        public array $duplicateIdentities = [],
        public array $malformedSourceLists = [],
        public array $filesMissingSourceList = [],
    ) {}
}
