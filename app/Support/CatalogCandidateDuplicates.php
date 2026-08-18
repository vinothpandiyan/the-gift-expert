<?php

namespace App\Support;

use App\Enums\CatalogCandidateSourceType;
use App\Models\CatalogCandidate;

final class CatalogCandidateDuplicates
{
    public static function findExternalReferenceDuplicate(
        CatalogCandidateSourceType $sourceType,
        ?string $sourceName,
        ?string $externalReference,
        ?int $ignoreCandidateId = null,
    ): ?CatalogCandidate {
        if ($externalReference === null) {
            return null;
        }

        $normalizedName = $sourceName === null ? '' : mb_strtolower($sourceName, 'UTF-8');

        return CatalogCandidate::query()
            ->where('source_type', $sourceType)
            ->where('external_reference', $externalReference)
            ->whereRaw('LOWER(TRIM(COALESCE(source_name, ""))) = ?', [$normalizedName])
            ->when($ignoreCandidateId !== null, fn ($query) => $query->whereKeyNot($ignoreCandidateId))
            ->first();
    }

    public static function findSourceUrlAndTitleDuplicate(
        ?string $sourceUrl,
        string $fingerprint,
        ?int $ignoreCandidateId = null,
    ): ?CatalogCandidate {
        if ($sourceUrl === null) {
            return null;
        }

        return CatalogCandidate::query()
            ->where('source_url', $sourceUrl)
            ->where('title_fingerprint', $fingerprint)
            ->when($ignoreCandidateId !== null, fn ($query) => $query->whereKeyNot($ignoreCandidateId))
            ->first();
    }

    public static function findSimilarTitle(
        string $fingerprint,
        ?int $ignoreCandidateId = null,
    ): ?CatalogCandidate {
        return CatalogCandidate::query()
            ->where('title_fingerprint', $fingerprint)
            ->when($ignoreCandidateId !== null, fn ($query) => $query->whereKeyNot($ignoreCandidateId))
            ->first();
    }
}
