<?php

namespace App\Models;

use App\Enums\ProductCurationRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductCurationAuditRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'status',
        'options',
        'summary',
        'products_total',
        'products_processed',
        'products_completed',
        'products_failed',
        'started_at',
        'finished_at',
        'failure',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductCurationRunStatus::class,
            'options' => 'array',
            'summary' => 'array',
            'products_total' => 'integer',
            'products_processed' => 'integer',
            'products_completed' => 'integer',
            'products_failed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->id ??= (string) Str::uuid();
        });
    }

    public function audits(): HasMany
    {
        return $this->hasMany(ProductCurationAudit::class, 'run_id');
    }
}
