<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\CuratedProductIntakeBatchReport;
use App\CuratedCatalog\CuratedProductIntakeHardStopException;
use App\CuratedCatalog\UnmappedCatalogSourceListsException;

class AssertCuratedProductIntakeBatchIsImportableAction
{
    /**
     * @return list<array{code: string, message: string, details: list<array<string, mixed>>}>
     */
    public function reasons(
        CuratedProductIntakeBatchReport $report,
        bool $allowUnknownSourceLists = false,
    ): array {
        $reasons = [];

        if ($report->unmappedLists !== [] && ! $allowUnknownSourceLists) {
            $reasons[] = [
                'code' => 'unmapped_source_lists',
                'message' => 'One or more source lists are unmapped (needs_source_mapping).',
                'details' => $report->unmappedLists,
            ];
        }

        if ($report->malformedSourceLists !== []) {
            $reasons[] = [
                'code' => 'malformed_source_list_identity',
                'message' => 'One or more source-list identities are malformed.',
                'details' => $report->malformedSourceLists,
            ];
        }

        if ($report->duplicateIdentities !== []) {
            $reasons[] = [
                'code' => 'duplicate_logical_wishlist_identity',
                'message' => 'The same logical wishlist identity appears in more than one file.',
                'details' => $report->duplicateIdentities,
            ];
        }

        if ($report->isDirectoryBatch && $report->filesMissingSourceList !== []) {
            $reasons[] = [
                'code' => 'missing_source_list_identity',
                'message' => 'Directory batches require JSON v2 source-list identity on every file.',
                'details' => array_map(
                    fn (string $file): array => ['file' => $file],
                    $report->filesMissingSourceList,
                ),
            ];
        }

        if ($this->malformedAsinsAreSignificant($report)) {
            $reasons[] = [
                'code' => 'significant_missing_asins',
                'message' => sprintf(
                    'Too many malformed or missing ASINs (%d of %d occurrences).',
                    $report->malformedExternalIds,
                    $report->rawOccurrences,
                ),
                'details' => [[
                    'malformed_external_ids' => $report->malformedExternalIds,
                    'raw_occurrences' => $report->rawOccurrences,
                ]],
            ];
        }

        return $reasons;
    }

    public function execute(
        CuratedProductIntakeBatchReport $report,
        bool $allowUnknownSourceLists = false,
    ): void {
        $reasons = $this->reasons($report, $allowUnknownSourceLists);

        if ($reasons === []) {
            return;
        }

        if (count($reasons) === 1 && $reasons[0]['code'] === 'unmapped_source_lists') {
            throw new UnmappedCatalogSourceListsException($report->unmappedLists);
        }

        throw new CuratedProductIntakeHardStopException($reasons);
    }

    private function malformedAsinsAreSignificant(CuratedProductIntakeBatchReport $report): bool
    {
        if ($report->malformedExternalIds <= 0) {
            return false;
        }

        $maxAbsolute = max(0, (int) config('curated_catalog.bulk_intake.max_malformed_external_ids', 5));

        if ($report->malformedExternalIds > $maxAbsolute) {
            return true;
        }

        $raw = max(0, $report->rawOccurrences);
        $minForRatio = max(1, (int) config('curated_catalog.bulk_intake.min_occurrences_for_malformed_ratio', 20));

        if ($raw < $minForRatio) {
            return false;
        }

        $maxRatio = (float) config('curated_catalog.bulk_intake.max_malformed_external_id_ratio', 0.05);

        return ($report->malformedExternalIds / $raw) > $maxRatio;
    }
}
