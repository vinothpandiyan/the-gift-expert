<?php

namespace App\Models;

use App\Enums\CuratedProductIntakeRunStatus;
use App\Enums\CuratedProductIntakeSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CuratedProductIntakeRun extends Model
{
    protected $fillable = [
        'merchant_id',
        'source_type',
        'status',
        'started_at',
        'finished_at',
        'items_total',
        'items_created',
        'items_updated',
        'items_skipped',
        'items_failed',
        'error',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => CuratedProductIntakeSourceType::class,
            'status' => CuratedProductIntakeRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'items_total' => 'integer',
            'items_created' => 'integer',
            'items_updated' => 'integer',
            'items_skipped' => 'integer',
            'items_failed' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CuratedProductIntakeItem::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
