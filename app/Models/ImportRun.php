<?php

namespace App\Models;

use App\Enums\ImportRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportRun extends Model
{
    protected $fillable = [
        'merchant_id',
        'provider_key',
        'status',
        'started_at',
        'finished_at',
        'items_total',
        'items_succeeded',
        'items_failed',
        'items_skipped',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'items_total' => 'integer',
            'items_succeeded' => 'integer',
            'items_failed' => 'integer',
            'items_skipped' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ImportRunItem::class);
    }
}
