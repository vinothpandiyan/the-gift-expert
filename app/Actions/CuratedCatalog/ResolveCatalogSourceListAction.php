<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\CatalogSourceListName;
use App\CuratedCatalog\CuratedSourceListContext;
use App\Enums\CatalogSourceListKind;
use App\Models\CatalogSourceList;
use App\Models\Merchant;
use App\Models\Relationship;
use InvalidArgumentException;

class ResolveCatalogSourceListAction
{
    public function __construct(
        private ResolveCatalogSourceListMappingAction $mapping,
    ) {}

    public function execute(Merchant $merchant, CuratedSourceListContext $context, bool $persist = true): CatalogSourceList
    {
        if (! $context->isPresent()) {
            throw new InvalidArgumentException('Source list identity is absent.');
        }

        $name = $context->name ?? ('List '.$context->externalListId);
        $normalizedName = CatalogSourceListName::normalize($name);

        if ($normalizedName === null) {
            throw new InvalidArgumentException('Source list identity could not be normalized.');
        }

        $mapping = $this->mapping->execute($merchant->slug, $context);
        $kind = CatalogSourceListKind::tryFrom($mapping->kind) ?? CatalogSourceListKind::Unknown;
        $relationshipId = $this->resolveRelationshipId($mapping->relationshipSlug);
        $existing = $this->findExisting($merchant, $context->externalListId, $normalizedName);

        if (! $persist) {
            return $existing ?? new CatalogSourceList([
                'merchant_id' => $merchant->id,
                'external_list_id' => $context->externalListId,
                'name' => $name,
                'normalized_name' => $normalizedName,
                'source_url' => $context->sourceUrl,
                'kind' => $kind,
                'relationship_id' => $relationshipId,
                'is_active' => true,
            ]);
        }

        if ($existing instanceof CatalogSourceList) {
            return $this->updateExisting(
                $existing,
                $context,
                $name,
                $normalizedName,
                $kind,
                $relationshipId,
            );
        }

        return CatalogSourceList::query()->create([
            'merchant_id' => $merchant->id,
            'external_list_id' => $context->externalListId,
            'name' => $name,
            'normalized_name' => $normalizedName,
            'source_url' => $context->sourceUrl,
            'kind' => $kind,
            'relationship_id' => $relationshipId,
            'is_active' => true,
        ]);
    }

    private function findExisting(Merchant $merchant, ?string $externalListId, string $normalizedName): ?CatalogSourceList
    {
        if ($externalListId !== null) {
            $identified = CatalogSourceList::query()
                ->where('merchant_id', $merchant->id)
                ->where('external_list_id', $externalListId)
                ->first();

            if ($identified instanceof CatalogSourceList) {
                return $identified;
            }

            return CatalogSourceList::query()
                ->where('merchant_id', $merchant->id)
                ->where('normalized_name', $normalizedName)
                ->whereNull('external_list_id')
                ->orderBy('id')
                ->first();
        }

        return CatalogSourceList::query()
            ->where('merchant_id', $merchant->id)
            ->where('normalized_name', $normalizedName)
            ->orderByRaw('external_list_id is null')
            ->orderBy('id')
            ->first();
    }

    private function updateExisting(
        CatalogSourceList $existing,
        CuratedSourceListContext $context,
        string $name,
        string $normalizedName,
        CatalogSourceListKind $kind,
        ?int $relationshipId,
    ): CatalogSourceList {
        if ($existing->external_list_id === null && $context->externalListId !== null) {
            $existing->external_list_id = $context->externalListId;
        }

        $existing->name = $name;
        $existing->normalized_name = $normalizedName;
        $existing->kind = $kind;
        $existing->relationship_id = $relationshipId;

        if ($context->sourceUrl !== null) {
            $existing->source_url = $context->sourceUrl;
        }

        $existing->save();

        return $existing->fresh();
    }

    private function resolveRelationshipId(?string $slug): ?int
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        $id = Relationship::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->value('id');

        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }
}
