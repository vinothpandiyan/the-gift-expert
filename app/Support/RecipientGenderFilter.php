<?php

namespace App\Support;

use App\Models\RecipientGender;
use Illuminate\Support\Facades\Cache;

/**
 * Expands user gender intent (male/female) to include unisex product matches.
 * Product classification stores exact values; expansion is query-only.
 */
final class RecipientGenderFilter
{
    /**
     * @param  list<int>  $selectedIds
     * @return list<int>
     */
    public static function expandMatchIds(array $selectedIds): array
    {
        $selectedIds = array_values(array_unique(array_map('intval', array_filter($selectedIds))));

        if ($selectedIds === []) {
            return [];
        }

        $byId = self::activeById();
        $unisexId = self::activeUnisexId();
        $expanded = $selectedIds;

        foreach ($selectedIds as $id) {
            $slug = $byId[$id] ?? null;

            if (
                $unisexId !== null
                && ($slug === RecipientGender::SLUG_MALE || $slug === RecipientGender::SLUG_FEMALE)
            ) {
                $expanded[] = $unisexId;
            }
        }

        return array_values(array_unique($expanded));
    }

    public static function expandMatchId(?int $selectedId): array
    {
        if ($selectedId === null || $selectedId < 1) {
            return [];
        }

        return self::expandMatchIds([$selectedId]);
    }

    /**
     * @return array<int, string>
     */
    private static function activeById(): array
    {
        return Cache::store('array')->remember('recipient_gender_active_by_id', 1, function (): array {
            return RecipientGender::query()
                ->where('is_active', true)
                ->pluck('slug', 'id')
                ->mapWithKeys(fn ($slug, $id): array => [(int) $id => (string) $slug])
                ->all();
        });
    }

    private static function activeUnisexId(): ?int
    {
        return Cache::store('array')->remember('recipient_gender_active_unisex_id', 1, function (): ?int {
            $id = RecipientGender::query()
                ->where('slug', RecipientGender::SLUG_UNISEX)
                ->where('is_active', true)
                ->value('id');

            return $id !== null ? (int) $id : null;
        });
    }
}
