<?php

namespace App\Models;

use App\Enums\CatalogCandidateSourcingRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogCandidateSourcingRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',
        'market',
        'started_at',
        'finished_at',
        'items_total',
        'items_succeeded',
        'items_skipped',
        'items_failed',
        'error',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogCandidateSourcingRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'items_total' => 'integer',
            'items_succeeded' => 'integer',
            'items_skipped' => 'integer',
            'items_failed' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CatalogCandidateSourcingItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
