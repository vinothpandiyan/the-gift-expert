<?php

namespace App\Models;

use App\Observers\CategoryObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

#[ObservedBy(CategoryObserver::class)]
class Category extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'sort_order',
        'is_active',
        'meta_title',
        'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('is_primary', 'created_at');
    }

    public function isDescendantOf(self $ancestor): bool
    {
        $current = $this->parent;

        while ($current !== null) {
            if ($current->is($ancestor)) {
                return true;
            }

            $current = $current->parent;
        }

        return false;
    }

    public static function assertValidParent(?int $parentId, ?int $categoryId = null): void
    {
        if ($parentId === null) {
            return;
        }

        if ($categoryId !== null && $parentId === $categoryId) {
            throw ValidationException::withMessages([
                'parent_id' => ['A category cannot be its own parent.'],
            ]);
        }

        if ($categoryId === null) {
            return;
        }

        $ancestor = self::query()->find($parentId);

        while ($ancestor !== null) {
            if ($ancestor->id === $categoryId) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A category cannot be moved under one of its descendants.'],
                ]);
            }

            $ancestor = $ancestor->parent;
        }
    }
}
