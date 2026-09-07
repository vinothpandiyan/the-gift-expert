<?php

namespace App\Enums;

use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;

enum TaxonomyDimension: string implements HasLabel
{
    case Category = 'category';
    case Occasion = 'occasion';
    case Relationship = 'relationship';
    case RecipientType = 'recipient_type';
    case Interest = 'interest';
    case Profession = 'profession';
    case GiftType = 'gift_type';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::RecipientType => 'Recipient type',
            self::GiftType => 'Gift type',
            default => $this->name,
        };
    }

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Category => Category::class,
            self::Occasion => Occasion::class,
            self::Relationship => Relationship::class,
            self::RecipientType => RecipientType::class,
            self::Interest => Interest::class,
            self::Profession => Profession::class,
            self::GiftType => GiftType::class,
        };
    }

    /**
     * Map GiftListing dimension keys onto semantic taxonomy dimensions.
     * BudgetRange is intentionally omitted.
     */
    public static function tryFromListingDimension(string $dimension): ?self
    {
        return match ($dimension) {
            'occasion' => self::Occasion,
            'relationship' => self::Relationship,
            'recipient' => self::RecipientType,
            'interest' => self::Interest,
            'profession' => self::Profession,
            'gift_type' => self::GiftType,
            'category' => self::Category,
            default => null,
        };
    }

    /**
     * @return array{table: string, foreign_key: string}
     */
    public function productPivot(): array
    {
        return match ($this) {
            self::Category => ['table' => 'category_product', 'foreign_key' => 'category_id'],
            self::Occasion => ['table' => 'occasion_product', 'foreign_key' => 'occasion_id'],
            self::Relationship => ['table' => 'relationship_product', 'foreign_key' => 'relationship_id'],
            self::RecipientType => ['table' => 'recipient_type_product', 'foreign_key' => 'recipient_type_id'],
            self::Interest => ['table' => 'interest_product', 'foreign_key' => 'interest_id'],
            self::Profession => ['table' => 'profession_product', 'foreign_key' => 'profession_id'],
            self::GiftType => ['table' => 'gift_type_product', 'foreign_key' => 'gift_type_id'],
        };
    }

    /**
     * Map discovery/product filter keys onto semantic taxonomy dimensions.
     * BudgetRange is intentionally omitted.
     */
    public static function tryFromFilterKey(string $key): ?self
    {
        return match ($key) {
            'occasion_id', 'occasion_ids' => self::Occasion,
            'relationship_id', 'relationship_ids' => self::Relationship,
            'recipient_type_id', 'recipient_type_ids' => self::RecipientType,
            'profession_id', 'profession_ids' => self::Profession,
            'gift_type_id', 'gift_type_ids' => self::GiftType,
            'category_id', 'category_ids' => self::Category,
            'interest_ids', 'any_interest_ids' => self::Interest,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{dimension: self, id: int}>
     */
    public static function contextsFromFilters(array $filters): array
    {
        $contexts = [];
        $seen = [];

        foreach ($filters as $key => $value) {
            $dimension = self::tryFromFilterKey((string) $key);

            if ($dimension === null) {
                continue;
            }

            $ids = is_array($value) ? $value : [$value];

            foreach ($ids as $id) {
                if ($id === null || $id === '') {
                    continue;
                }

                $id = (int) $id;

                if ($id < 1) {
                    continue;
                }

                $token = $dimension->value.':'.$id;

                if (isset($seen[$token])) {
                    continue;
                }

                $seen[$token] = true;
                $contexts[] = [
                    'dimension' => $dimension,
                    'id' => $id,
                ];
            }
        }

        return $contexts;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function activeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        return $this->modelClass()::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function endpointExists(int $id): bool
    {
        return $this->modelClass()::query()->whereKey($id)->exists();
    }

    /**
     * @return array<int, string>
     */
    public function valueOptions(): array
    {
        $query = $this->modelClass()::query()
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($this === self::Category) {
            return $query
                ->get(['id', 'name', 'full_path', 'is_active'])
                ->mapWithKeys(function (Model $record): array {
                    $label = filled($record->full_path) ? (string) $record->full_path : (string) $record->name;

                    if (! $record->is_active) {
                        $label .= ' (inactive)';
                    }

                    return [(int) $record->id => $label];
                })
                ->all();
        }

        return $query
            ->get(['id', 'name', 'is_active'])
            ->mapWithKeys(function (Model $record): array {
                $label = (string) $record->name;

                if (! $record->is_active) {
                    $label .= ' (inactive)';
                }

                return [(int) $record->id => $label];
            })
            ->all();
    }

    public function valueLabel(int $id): string
    {
        $record = $this->modelClass()::query()->find($id);

        if ($record === null) {
            return "Unknown #{$id}";
        }

        $label = $this === self::Category && filled($record->full_path)
            ? (string) $record->full_path
            : (string) $record->name;

        if (! $record->is_active) {
            $label .= ' (inactive)';
        }

        return $label;
    }
}
