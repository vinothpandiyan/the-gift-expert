<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecipientGender extends Model
{
    use SoftDeletes;

    public const SLUG_MALE = 'male';

    public const SLUG_FEMALE = 'female';

    public const SLUG_UNISEX = 'unisex';

    protected $fillable = [
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

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'recipient_gender_product')
            ->withPivot('created_at');
    }

    public function isMale(): bool
    {
        return $this->slug === self::SLUG_MALE;
    }

    public function isFemale(): bool
    {
        return $this->slug === self::SLUG_FEMALE;
    }

    public function isUnisex(): bool
    {
        return $this->slug === self::SLUG_UNISEX;
    }
}
