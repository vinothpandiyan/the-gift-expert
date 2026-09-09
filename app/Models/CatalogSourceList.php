<?php

namespace App\Models;

use App\Enums\CatalogSourceListKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogSourceList extends Model
{
    protected $fillable = [
        'merchant_id',
        'external_list_id',
        'name',
        'normalized_name',
        'source_url',
        'kind',
        'relationship_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => CatalogSourceListKind::class,
            'is_active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }

    public function productSources(): HasMany
    {
        return $this->hasMany(CatalogProductSource::class);
    }

    public function isProvisional(): bool
    {
        return $this->external_list_id === null;
    }
}
