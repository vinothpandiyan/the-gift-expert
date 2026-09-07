<?php

namespace App\Actions\CatalogCandidate;

use App\CommercialSourcing\ValidatedProductTaxonomyClassification;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use Illuminate\Database\Eloquent\Model;

class ValidateProductTaxonomyClassificationAction
{
    /**
     * @param  array<string, mixed>  $taxonomy
     * @param  array<string, int>  $capOverrides
     */
    public function execute(array $taxonomy, array $capOverrides = []): ValidatedProductTaxonomyClassification
    {
        $rejected = [];
        $codes = [];

        $categoryIds = $this->acceptedIds(
            $taxonomy['category_ids'] ?? [],
            Category::class,
            $this->cap('categories', $capOverrides),
            $rejected,
        );
        $occasionIds = $this->acceptedIds(
            $taxonomy['occasion_ids'] ?? [],
            Occasion::class,
            $this->cap('occasions', $capOverrides),
            $rejected,
        );
        $relationshipIds = $this->acceptedIds(
            $taxonomy['relationship_ids'] ?? [],
            Relationship::class,
            $this->cap('relationships', $capOverrides),
            $rejected,
        );
        $recipientTypeIds = $this->acceptedIds(
            $taxonomy['recipient_type_ids'] ?? [],
            RecipientType::class,
            $this->cap('recipient_types', $capOverrides),
            $rejected,
        );
        $interestIds = $this->acceptedIds(
            $taxonomy['interest_ids'] ?? [],
            Interest::class,
            $this->cap('interests', $capOverrides),
            $rejected,
        );
        $professionIds = $this->acceptedIds(
            $taxonomy['profession_ids'] ?? [],
            Profession::class,
            $this->cap('professions', $capOverrides),
            $rejected,
        );
        $giftTypeIds = $this->acceptedIds(
            $taxonomy['gift_type_ids'] ?? [],
            GiftType::class,
            $this->cap('gift_types', $capOverrides),
            $rejected,
        );

        $primary = $this->nullableInt($taxonomy['primary_category_id'] ?? null);
        $primaryCategoryId = $this->resolvePrimary($primary, $categoryIds, $rejected);

        if ($primaryCategoryId === null) {
            $codes[] = 'missing_primary_category';
        } elseif (! in_array($primaryCategoryId, $categoryIds, true)) {
            array_unshift($categoryIds, $primaryCategoryId);
            $categoryIds = array_slice($categoryIds, 0, $this->cap('categories', $capOverrides));
        }

        if ($rejected !== []) {
            $codes[] = 'taxonomy_ids_rejected';
        }

        if (
            count($occasionIds) >= $this->cap('occasions', $capOverrides)
            && count($relationshipIds) >= $this->cap('relationships', $capOverrides)
            && count($interestIds) >= $this->cap('interests', $capOverrides)
        ) {
            $codes[] = 'taxonomy_too_broad';
        }

        return new ValidatedProductTaxonomyClassification(
            primaryCategoryId: $primaryCategoryId,
            categoryIds: $categoryIds,
            occasionIds: $occasionIds,
            relationshipIds: $relationshipIds,
            recipientTypeIds: $recipientTypeIds,
            interestIds: $interestIds,
            professionIds: $professionIds,
            giftTypeIds: $giftTypeIds,
            exceptionCodes: array_values(array_unique($codes)),
            rejectedIds: array_values(array_unique($rejected)),
        );
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<int>  $rejected
     * @return list<int>
     */
    private function acceptedIds(mixed $raw, string $model, int $cap, array &$rejected): array
    {
        $accepted = [];

        foreach ($this->normalizeIds($raw) as $id) {
            if (in_array($id, $accepted, true)) {
                continue;
            }

            $record = $model::query()->whereKey($id)->first();

            if (! $record instanceof Model || $record->is_active !== true) {
                $rejected[] = $id;

                continue;
            }

            if ($model === Category::class && ! $this->isAcceptableMerchandisingCategory($id)) {
                $rejected[] = $id;

                continue;
            }

            if (count($accepted) >= $cap) {
                $rejected[] = $id;

                continue;
            }

            $accepted[] = $id;
        }

        return $accepted;
    }

    /**
     * @param  list<int>  $categoryIds
     * @param  list<int>  $rejected
     */
    private function resolvePrimary(?int $primary, array &$categoryIds, array &$rejected): ?int
    {
        if ($primary !== null) {
            if ($this->isAcceptableMerchandisingCategory($primary)) {
                return $primary;
            }

            $rejected[] = $primary;
        }

        foreach ($categoryIds as $id) {
            if ($this->isAcceptableMerchandisingCategory($id)) {
                return $id;
            }
        }

        return null;
    }

    private function isAcceptableMerchandisingCategory(int $categoryId): bool
    {
        $category = Category::query()->whereKey($categoryId)->first();

        if (! $category instanceof Category || $category->is_active !== true) {
            return false;
        }

        if (! is_string($category->full_path) || trim($category->full_path) === '') {
            return false;
        }

        if ($category->canonical_seo_landing_page_id !== null) {
            return false;
        }

        $current = $category;

        while ($current->parent_id !== null) {
            $parent = Category::withTrashed()->find($current->parent_id);

            if (! $parent instanceof Category) {
                break;
            }

            if ($parent->canonical_seo_landing_page_id !== null) {
                return false;
            }

            $current = $parent;
        }

        $slug = $category->slug;

        if ($slug === 'personalized-gifts' || str_starts_with($slug, 'gifts-for-')) {
            return false;
        }

        $occasionSlugs = Occasion::query()->where('is_active', true)->pluck('slug')->all();

        foreach ($occasionSlugs as $occasionSlug) {
            if (is_string($occasionSlug) && $slug === $occasionSlug.'-gifts') {
                return false;
            }
        }

        $intentSlugs = Relationship::query()->where('is_active', true)->pluck('slug')
            ->merge(RecipientType::query()->where('is_active', true)->pluck('slug'))
            ->all();

        return ! in_array($slug, $intentSlugs, true);
    }

    /**
     * @return list<int>
     */
    private function normalizeIds(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $value) {
            $id = $this->nullableInt($value);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<string, int>  $overrides
     */
    private function cap(string $key, array $overrides): int
    {
        if (array_key_exists($key, $overrides)) {
            return max(1, (int) $overrides[$key]);
        }

        return max(1, (int) config('commercial_sourcing.taxonomy_caps.'.$key, 1));
    }
}
