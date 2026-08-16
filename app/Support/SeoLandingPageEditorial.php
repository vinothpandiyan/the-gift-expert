<?php

namespace App\Support;

use App\Enums\SeoLandingPageStatus;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;

final class SeoLandingPageEditorial
{
    /**
     * @return array{
     *     occasion_id: int|null,
     *     relationship_id: int|null,
     *     recipient_type_id: int|null,
     *     profession_id: int|null,
     *     gift_type_id: int|null,
     *     category_id: int|null,
     *     budget_range_id: int|null,
     *     interest_ids: list<int>,
     * }
     */
    public static function productFilters(SeoLandingPage $page): array
    {
        $interestIds = $page->relationLoaded('interests')
            ? $page->interests->pluck('id')->all()
            : $page->interests()->pluck('interests.id')->all();

        return [
            'occasion_id' => self::nullableId($page->occasion_id),
            'relationship_id' => self::nullableId($page->relationship_id),
            'recipient_type_id' => self::nullableId($page->recipient_type_id),
            'profession_id' => self::nullableId($page->profession_id),
            'gift_type_id' => self::nullableId($page->gift_type_id),
            'category_id' => self::nullableId($page->category_id),
            'budget_range_id' => self::nullableId($page->budget_range_id),
            'interest_ids' => self::normalizedInterestIds($interestIds),
        ];
    }

    /**
     * True when the filters copy a single taxonomy URL (e.g. relationship only).
     * Budget-only pages and multi-dimension pages are not taxonomy duplicates.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function duplicatesTaxonomyIntent(array $filters): bool
    {
        if (self::nullableId($filters['budget_range_id'] ?? null) !== null) {
            return false;
        }

        $taxonomyCount = 0;

        foreach (['occasion_id', 'relationship_id', 'recipient_type_id', 'profession_id', 'gift_type_id', 'category_id'] as $column) {
            if (self::nullableId($filters[$column] ?? null) !== null) {
                $taxonomyCount++;
            }
        }

        $interestCount = count(self::normalizedInterestIds($filters['interest_ids'] ?? []));

        if ($taxonomyCount === 1 && $interestCount === 0) {
            return true;
        }

        return $taxonomyCount === 0 && $interestCount === 1;
    }

    public static function findPublishedDuplicate(SeoLandingPage $page): ?SeoLandingPage
    {
        $filters = self::productFilters($page);

        $candidates = SeoLandingPage::query()
            ->where('status', SeoLandingPageStatus::Published)
            ->whereKeyNot($page->getKey() ?? 0)
            ->where('occasion_id', $filters['occasion_id'])
            ->where('relationship_id', $filters['relationship_id'])
            ->where('recipient_type_id', $filters['recipient_type_id'])
            ->where('profession_id', $filters['profession_id'])
            ->where('gift_type_id', $filters['gift_type_id'])
            ->where('category_id', $filters['category_id'])
            ->where('budget_range_id', $filters['budget_range_id'])
            ->with('interests')
            ->get();

        foreach ($candidates as $candidate) {
            $candidateInterestIds = self::normalizedInterestIds($candidate->interests->pluck('id')->all());

            if ($candidateInterestIds === $filters['interest_ids']) {
                return $candidate;
            }
        }

        return null;
    }

    public static function categoryIsMappedToLandingPage(mixed $categoryId): bool
    {
        $id = self::nullableId($categoryId);

        if ($id === null) {
            return false;
        }

        return Category::query()
            ->whereKey($id)
            ->whereNotNull('canonical_seo_landing_page_id')
            ->exists();
    }

    public static function taxonomySlugTaken(string $slug): bool
    {
        foreach ([
            Relationship::class,
            Occasion::class,
            RecipientType::class,
            Profession::class,
            GiftType::class,
            Interest::class,
            Category::class,
        ] as $model) {
            if ($model::query()->where('slug', $slug)->exists()) {
                return true;
            }
        }

        return false;
    }

    public static function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return list<int>
     */
    public static function normalizedInterestIds(mixed $interestIds): array
    {
        return collect(is_array($interestIds) ? $interestIds : [])
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
