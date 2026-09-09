<?php

namespace App\Actions\CuratedCatalog;

use App\CommercialSourcing\CommercialSourcingMerchants;
use App\CommercialSourcing\ExtractCommercialExternalProductId;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\CuratedCatalog\CuratedProductInputError;
use App\CuratedCatalog\CuratedProductIntakeParseException;
use App\CuratedCatalog\Parsing\CuratedProductIntakeFields;
use JsonException;

class ParseCuratedMerchantProductsAction
{
    public function __construct(
        private CommercialSourcingMerchants $merchants,
        private ExtractCommercialExternalProductId $extractExternalId,
    ) {}

    /**
     * @return list<CuratedMerchantProductInput|CuratedProductInputError>
     */
    public function execute(
        string $json,
        ?string $formMerchantSlug = null,
        ?string $formCurationGroup = null,
        int $itemIndexOffset = 0,
    ): array {
        $contents = CuratedProductIntakeFields::assertUtf8(trim($json));

        if ($contents === '') {
            throw new CuratedProductIntakeParseException('JSON payload is required.');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CuratedProductIntakeParseException('The JSON payload is malformed.');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new CuratedProductIntakeParseException('The JSON payload must be an object.');
        }

        $allowedRootKeys = ['version', 'merchant', 'captured_at', 'context', 'items'];
        $unknown = CuratedProductIntakeFields::unknownKeys($decoded, $allowedRootKeys);

        if ($unknown !== []) {
            throw new CuratedProductIntakeParseException(
                'Unknown top-level JSON fields are not allowed: '.implode(', ', $unknown).'.',
            );
        }

        $version = $decoded['version'] ?? null;
        $allowedVersions = config('curated_catalog.allowed_schema_versions', [1]);

        if (! is_array($allowedVersions) || ! in_array((int) $version, array_map('intval', $allowedVersions), true)) {
            throw new CuratedProductIntakeParseException('Unsupported JSON schema version.');
        }

        $merchantSlug = CuratedProductIntakeFields::nullableString($formMerchantSlug)
            ?? CuratedProductIntakeFields::nullableString($decoded['merchant'] ?? null);

        if ($merchantSlug === null) {
            throw new CuratedProductIntakeParseException('merchant is required.');
        }

        $merchantConfig = config('curated_catalog.merchants.'.$merchantSlug);

        if (! is_array($merchantConfig) || ($merchantConfig['enabled'] ?? false) !== true) {
            throw new CuratedProductIntakeParseException('The selected merchant is not enabled for curated intake.');
        }

        if (! is_array($decoded['items'] ?? null) || ! array_is_list($decoded['items'])) {
            throw new CuratedProductIntakeParseException('items must be an array.');
        }

        $maxItems = max(1, (int) config('curated_catalog.max_items', 200));

        if (count($decoded['items']) > $maxItems) {
            throw new CuratedProductIntakeParseException('The payload exceeds the maximum number of items.');
        }

        if ($decoded['items'] === []) {
            throw new CuratedProductIntakeParseException('items must contain at least one entry.');
        }

        $rootCapturedAt = CuratedProductIntakeFields::nullableString($decoded['captured_at'] ?? null);
        $rootCurationGroup = null;
        $sourceListContext = null;

        if (is_array($decoded['context'] ?? null)) {
            $allowedContextKeys = ['curation_group'];

            if ((int) $version >= 2) {
                $allowedContextKeys = [
                    'curation_group',
                    'source_list_name',
                    'source_list_id',
                    'source_list_url',
                    'list_kind',
                ];
            }

            $contextUnknown = CuratedProductIntakeFields::unknownKeys($decoded['context'], $allowedContextKeys);

            if ($contextUnknown !== []) {
                throw new CuratedProductIntakeParseException(
                    'Unknown context fields are not allowed: '.implode(', ', $contextUnknown).'.',
                );
            }

            $rootCurationGroup = CuratedProductIntakeFields::nullableString($decoded['context']['curation_group'] ?? null);
            $sourceListContext = CuratedProductIntakeFields::sourceListContextFromRow(
                $decoded['context'],
                (int) $version,
            );
        }

        $results = [];

        foreach ($decoded['items'] as $offset => $item) {
            $itemIndex = $itemIndexOffset + $offset + 1;

            if (! is_array($item) || array_is_list($item)) {
                $results[] = new CuratedProductInputError($itemIndex, 'invalid_item', 'Each item must be an object.');

                continue;
            }

            $results[] = CuratedProductIntakeFields::itemFromRow(
                itemIndex: $itemIndex,
                merchantSlug: $merchantSlug,
                row: $item,
                rootCapturedAt: $rootCapturedAt,
                rootCurationGroup: $rootCurationGroup,
                formCurationGroup: $formCurationGroup,
                merchants: $this->merchants,
                extractExternalId: $this->extractExternalId,
                sourceListContext: $sourceListContext,
            );
        }

        return $results;
    }
}
