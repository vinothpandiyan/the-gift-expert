<?php

namespace App\Models;

use App\Enums\AffiliateLinkStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AffiliateLink extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'merchant_id',
        'url',
        'external_product_id',
        'is_primary',
        'status',
        'last_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'status' => AffiliateLinkStatus::class,
            'last_verified_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AffiliateLinkStatus::Active);
    }
}
