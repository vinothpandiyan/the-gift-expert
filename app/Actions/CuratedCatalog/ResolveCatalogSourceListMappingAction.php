<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\CatalogSourceListMapping;
use App\CuratedCatalog\CatalogSourceListName;
use App\CuratedCatalog\CuratedSourceListContext;
use App\Enums\CatalogSourceListKind;

class ResolveCatalogSourceListMappingAction
{
    public function execute(string $merchantSlug, CuratedSourceListContext $context): CatalogSourceListMapping
    {
        $config = config('curated_catalog.source_lists.'.$merchantSlug, []);
        $byId = is_array($config['by_external_list_id'] ?? null) ? $config['by_external_list_id'] : [];
        $byName = is_array($config['by_normalized_name'] ?? null) ? $config['by_normalized_name'] : [];
        $patterns = is_array($config['normalized_name_patterns'] ?? null) ? $config['normalized_name_patterns'] : [];

        if ($context->externalListId !== null && isset($byId[$context->externalListId]) && is_array($byId[$context->externalListId])) {
            return $this->fromConfigRow($byId[$context->externalListId], 'external_list_id');
        }

        $normalizedName = CatalogSourceListName::normalize($context->name);

        if ($normalizedName !== null && isset($byName[$normalizedName]) && is_array($byName[$normalizedName])) {
            return $this->fromConfigRow($byName[$normalizedName], 'normalized_name');
        }

        if ($normalizedName !== null) {
            foreach ($patterns as $pattern => $row) {
                if (! is_string($pattern) || ! is_array($row) || @preg_match($pattern, $normalizedName) !== 1) {
                    continue;
                }

                return $this->fromConfigRow($row, 'normalized_name_pattern');
            }
        }

        return new CatalogSourceListMapping(
            kind: CatalogSourceListKind::Unknown->value,
            relationshipSlug: null,
            isMapped: false,
            matchedBy: 'unmapped',
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function fromConfigRow(array $row, string $matchedBy): CatalogSourceListMapping
    {
        $kind = is_string($row['kind'] ?? null) ? $row['kind'] : CatalogSourceListKind::Unknown->value;
        $relationship = is_string($row['relationship'] ?? null) ? $row['relationship'] : null;

        if ($kind !== CatalogSourceListKind::RecipientHint->value) {
            $relationship = null;
        }

        return new CatalogSourceListMapping(
            kind: $kind,
            relationshipSlug: $relationship,
            isMapped: CatalogSourceListKind::tryFrom($kind) !== null && $kind !== CatalogSourceListKind::Unknown->value,
            matchedBy: $matchedBy,
        );
    }
}
