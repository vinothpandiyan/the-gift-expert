<?php

namespace App\CuratedCatalog;

use App\Enums\TaxonomyDimension;
use App\Models\Category;
use App\Models\Product;

class ProductTaxonomyFormState
{
    /**
     * @return array{
     *     primary_category_id: int|null,
     *     relationship_ids: list<int>,
     *     recipient_type_ids: list<int>,
     *     occasion_ids: list<int>,
     *     interest_ids: list<int>,
     *     profession_ids: list<int>,
     *     gift_type_ids: list<int>
     * }
     */
    public static function fromProduct(Product $product): array
    {
        $product->loadMissing([
            'categories',
            'relationships',
            'recipientTypes',
            'occasions',
            'interests',
            'professions',
            'giftTypes',
        ]);

        $primary = $product->categories
            ->first(fn (Category $category): bool => (bool) $category->pivot->is_primary && $category->is_active);

        return [
            'primary_category_id' => $primary?->id,
            'relationship_ids' => self::activeIds($product->relationships),
            'recipient_type_ids' => self::activeIds($product->recipientTypes),
            'occasion_ids' => self::activeIds($product->occasions),
            'interest_ids' => self::activeIds($product->interests),
            'profession_ids' => self::activeIds($product->professions),
            'gift_type_ids' => self::activeIds($product->giftTypes),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     primary_category_id: int|null,
     *     category_ids: list<int>,
     *     relationship_ids: list<int>,
     *     recipient_type_ids: list<int>,
     *     occasion_ids: list<int>,
     *     interest_ids: list<int>,
     *     profession_ids: list<int>,
     *     gift_type_ids: list<int>
     * }
     */
    public static function fromForm(array $data): array
    {
        $primary = self::nullableId($data['primary_category_id'] ?? null);
        $relationships = self::idList($data['relationship_ids'] ?? []);
        $recipientTypes = self::idList($data['recipient_type_ids'] ?? []);
        $occasions = self::idList($data['occasion_ids'] ?? []);
        $interests = self::idList($data['interest_ids'] ?? []);
        $professions = self::idList($data['profession_ids'] ?? []);
        $giftTypes = self::idList($data['gift_type_ids'] ?? []);

        return [
            'primary_category_id' => $primary,
            'category_ids' => $primary !== null ? [$primary] : [],
            'relationship_ids' => $relationships,
            'recipient_type_ids' => $recipientTypes,
            'occasion_ids' => $occasions,
            'interest_ids' => $interests,
            'profession_ids' => $professions,
            'gift_type_ids' => $giftTypes,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     */
    public static function materiallyChanged(array $current, array $incoming): bool
    {
        return self::normalize($current) !== self::normalize($incoming);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function normalize(array $state): array
    {
        return [
            'primary_category_id' => self::nullableId($state['primary_category_id'] ?? null),
            'relationship_ids' => self::idList($state['relationship_ids'] ?? []),
            'recipient_type_ids' => self::idList($state['recipient_type_ids'] ?? []),
            'occasion_ids' => self::idList($state['occasion_ids'] ?? []),
            'interest_ids' => self::idList($state['interest_ids'] ?? []),
            'profession_ids' => self::idList($state['profession_ids'] ?? []),
            'gift_type_ids' => self::idList($state['gift_type_ids'] ?? []),
        ];
    }

    /**
     * @param  iterable<int, object>  $records
     * @return list<int>
     */
    private static function activeIds(iterable $records): array
    {
        $ids = [];

        foreach ($records as $record) {
            if (($record->is_active ?? false) !== true) {
                continue;
            }

            $ids[] = (int) $record->id;
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private static function idList(mixed $raw): array
    {
        if (! is_array($raw)) {
            $id = self::nullableId($raw);

            return $id !== null ? [$id] : [];
        }

        $ids = [];

        foreach ($raw as $value) {
            $id = self::nullableId($value);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    private static function nullableId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    public static function names(TaxonomyDimension $dimension, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $dimension->modelClass()::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get()
            ->map(fn ($record): string => (string) $record->name)
            ->all();
    }
}
