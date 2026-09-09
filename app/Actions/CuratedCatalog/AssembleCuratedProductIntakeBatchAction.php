<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\CatalogSourceListName;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\CuratedCatalog\CuratedProductInputError;
use App\CuratedCatalog\CuratedProductIntakeBatchReport;
use App\CuratedCatalog\CuratedProductIntakeParseException;
use App\CuratedCatalog\CuratedSourceListContext;
use App\Enums\CatalogSourceListKind;
use InvalidArgumentException;

class AssembleCuratedProductIntakeBatchAction
{
    public function __construct(
        private ParseCuratedMerchantProductsAction $parse,
        private PreviewCuratedProductIntakeAction $preview,
        private ResolveCatalogSourceListMappingAction $mapping,
    ) {}

    public function execute(string $path): CuratedProductIntakeBatchReport
    {
        [$files, $isDirectoryBatch] = $this->discoverFiles($path);
        $parsed = [];
        $merchantSlug = null;
        $itemIndexOffset = 0;
        $sourceListBuckets = [];
        $filesMissingSourceList = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                throw new CuratedProductIntakeParseException("The intake file [{$file}] could not be read.");
            }

            try {
                $fileRows = $this->parse->execute($contents, itemIndexOffset: $itemIndexOffset);
            } catch (CuratedProductIntakeParseException $exception) {
                throw new CuratedProductIntakeParseException(
                    'Invalid intake file ['.basename($file).']: '.$exception->getMessage(),
                    previous: $exception,
                );
            }

            $fileMerchant = $this->merchantSlugFromFile($contents);

            if ($merchantSlug === null) {
                $merchantSlug = $fileMerchant;
            } elseif ($fileMerchant !== $merchantSlug) {
                throw new CuratedProductIntakeParseException('Batch intake files must share a single merchant.');
            }

            $itemIndexOffset += count($fileRows);
            $parsed = array_merge($parsed, $fileRows);

            if (! $this->collectSourceList($sourceListBuckets, $file, $fileRows)) {
                $filesMissingSourceList[] = basename($file);
            }
        }

        $preview = $this->preview->fromParsed($parsed, (string) $merchantSlug);
        $sourceLists = array_values($sourceListBuckets);
        $kindCounts = [
            CatalogSourceListKind::RecipientHint->value => 0,
            CatalogSourceListKind::UnclassifiedInbox->value => 0,
            CatalogSourceListKind::QuarterlyArchive->value => 0,
            CatalogSourceListKind::Unknown->value => 0,
        ];
        $unmapped = [];
        $malformedSourceLists = [];
        $duplicateIdentities = [];
        $productsBySourceList = [];
        $productsByRelationshipHint = [];

        foreach ($sourceLists as $sourceList) {
            $kind = $sourceList['kind'];
            $kindCounts[$kind] = ($kindCounts[$kind] ?? 0) + 1;

            if ($sourceList['mapped'] !== true) {
                $unmapped[] = [
                    'name' => $sourceList['name'],
                    'external_list_id' => $sourceList['external_list_id'],
                    'url' => $sourceList['url'],
                    'product_count' => $sourceList['product_count'],
                    'status' => 'needs_source_mapping',
                ];
            }

            if (($sourceList['malformed'] ?? false) === true) {
                $malformedSourceLists[] = [
                    'name' => $sourceList['name'],
                    'file' => $sourceList['files'][0] ?? null,
                    'reason' => $sourceList['malformed_reason'] ?? 'Source-list identity is malformed.',
                ];
            }

            $uniqueFiles = array_values(array_unique($sourceList['files'] ?? []));

            if (count($uniqueFiles) > 1) {
                $duplicateIdentities[] = [
                    'name' => $sourceList['name'],
                    'identity_key' => $sourceList['identity_key'] ?? null,
                    'files' => $uniqueFiles,
                ];
            }
        }

        foreach ($preview->items as $item) {
            foreach ($item->sourceListNames as $name) {
                $productsBySourceList[$name] = ($productsBySourceList[$name] ?? 0) + 1;
            }

            foreach ($item->relationshipHintNames as $hint) {
                $productsByRelationshipHint[$hint] = ($productsByRelationshipHint[$hint] ?? 0) + 1;
            }
        }

        ksort($productsBySourceList);
        ksort($productsByRelationshipHint);

        $multiRecipient = 0;
        $conflicts = [];
        $conflictFieldCounts = [
            'title' => 0,
            'price' => 0,
            'availability' => 0,
            'image' => 0,
        ];

        foreach ($preview->items as $item) {
            if (count($item->relationshipHintNames) > 1) {
                $multiRecipient++;
            }

            if ($item->commercialConflicts !== []) {
                $conflicts[] = [
                    'external_product_id' => $item->externalProductId(),
                    'fields' => $item->commercialConflicts,
                ];

                foreach ($item->commercialConflicts as $field) {
                    if (isset($conflictFieldCounts[$field])) {
                        $conflictFieldCounts[$field]++;
                    }
                }
            }
        }

        $malformedExternalIds = 0;

        foreach ($parsed as $row) {
            if ($row instanceof CuratedProductInputError && in_array($row->code, [
                'missing_external_product_id',
                'invalid_asin',
                'asin_url_mismatch',
            ], true)) {
                $malformedExternalIds++;
            }
        }

        return new CuratedProductIntakeBatchReport(
            merchantSlug: (string) $merchantSlug,
            files: $files,
            wishlistCount: count($sourceLists),
            rawOccurrences: $preview->rawOccurrences,
            uniqueProducts: $preview->uniqueProducts,
            mergedOccurrences: $preview->mergedOccurrences,
            multiListProducts: $preview->multiListProducts,
            newProducts: $preview->itemsNew,
            existingProducts: $preview->itemsExisting,
            recipientHintLists: $kindCounts[CatalogSourceListKind::RecipientHint->value],
            unclassifiedLists: $kindCounts[CatalogSourceListKind::UnclassifiedInbox->value],
            quarterlyArchiveLists: $kindCounts[CatalogSourceListKind::QuarterlyArchive->value],
            unknownLists: $kindCounts[CatalogSourceListKind::Unknown->value],
            malformedExternalIds: $malformedExternalIds,
            commercialConflicts: count($conflicts),
            multiRecipientProducts: $multiRecipient,
            sourceLists: $sourceLists,
            unmappedLists: $unmapped,
            productsBySourceList: $productsBySourceList,
            productsByRelationshipHint: $productsByRelationshipHint,
            commercialConflictItems: $conflicts,
            preview: $preview,
            isDirectoryBatch: $isDirectoryBatch,
            commercialConflictFieldCounts: $conflictFieldCounts,
            duplicateIdentities: $duplicateIdentities,
            malformedSourceLists: $malformedSourceLists,
            filesMissingSourceList: $filesMissingSourceList,
        );
    }

    /**
     * @return array{0: list<string>, 1: bool}
     */
    public function discoverFiles(string $path): array
    {
        $normalized = strtolower($path);

        foreach (['http://', 'https://', 'ftp://', 'php://', 'file://'] as $scheme) {
            if (str_starts_with($normalized, $scheme)) {
                throw new InvalidArgumentException('Remote URLs are not allowed. Provide a local file or directory path.');
            }
        }

        $realPath = realpath($path);

        if ($realPath === false || ! is_readable($realPath)) {
            throw new InvalidArgumentException("Path [{$path}] was not found or is not readable.");
        }

        if (is_file($realPath)) {
            if (strtolower(pathinfo($realPath, PATHINFO_EXTENSION)) !== 'json') {
                throw new InvalidArgumentException('Intake files must have a .json extension.');
            }

            return [[$realPath], false];
        }

        if (! is_dir($realPath)) {
            throw new InvalidArgumentException("Path [{$path}] is not a file or directory.");
        }

        $files = glob($realPath.DIRECTORY_SEPARATOR.'*.json') ?: [];
        $files = array_values(array_filter($files, fn (string $file): bool => is_file($file)));
        sort($files);

        if ($files === []) {
            throw new InvalidArgumentException("No JSON files were found in [{$path}].");
        }

        return [$files, true];
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     * @param  list<CuratedMerchantProductInput|CuratedProductInputError>  $rows
     */
    private function collectSourceList(array &$buckets, string $file, array $rows): bool
    {
        $context = null;
        $productIds = [];

        foreach ($rows as $row) {
            if (! $row instanceof CuratedMerchantProductInput) {
                continue;
            }

            $context ??= $row->sourceListContext;

            if ($row->sourceListContext?->isPresent() || ($row->sourceListContext?->malformed ?? false)) {
                $productIds[$row->externalProductId] = true;
            }
        }

        if (! $context instanceof CuratedSourceListContext) {
            return false;
        }

        if (! $context->isPresent() && ! $context->malformed) {
            return false;
        }

        $mapping = $this->mapping->execute($this->merchantFromRows($rows), $context);
        $key = $context->identityKey() ?? 'malformed:'.basename($file);
        $kind = $mapping->isMapped ? $mapping->kind : CatalogSourceListKind::Unknown->value;

        if (! isset($buckets[$key])) {
            $buckets[$key] = [
                'name' => $context->displayName(),
                'normalized_name' => CatalogSourceListName::normalize($context->name),
                'external_list_id' => $context->externalListId,
                'url' => $context->sourceUrl,
                'kind' => $kind,
                'mapped' => $mapping->isMapped,
                'relationship' => $mapping->relationshipSlug,
                'product_count' => 0,
                'files' => [],
                'identity_key' => $context->identityKey(),
                'malformed' => $context->malformed,
                'malformed_reason' => $context->malformedReason,
            ];
        } else {
            $buckets[$key]['malformed'] = $buckets[$key]['malformed'] || $context->malformed;
            $buckets[$key]['malformed_reason'] ??= $context->malformedReason;
        }

        $buckets[$key]['product_count'] += count($productIds);
        $buckets[$key]['files'][] = basename($file);

        return true;
    }

    /**
     * @param  list<CuratedMerchantProductInput|CuratedProductInputError>  $rows
     */
    private function merchantFromRows(array $rows): string
    {
        foreach ($rows as $row) {
            if ($row instanceof CuratedMerchantProductInput) {
                return $row->merchantSlug;
            }
        }

        return '';
    }

    private function merchantSlugFromFile(string $json): string
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return '';
        }

        return trim((string) ($decoded['merchant'] ?? ''));
    }
}
