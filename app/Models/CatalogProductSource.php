<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogProductSource extends Model
{
    protected $fillable = [
        'affiliate_link_id',
        'catalog_source_list_id',
        'first_seen_at',
        'last_seen_at',
        'first_intake_run_id',
        'last_intake_run_id',
        'occurrence_count',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'occurrence_count' => 'integer',
        ];
    }

    public function affiliateLink(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class);
    }

    public function sourceList(): BelongsTo
    {
        return $this->belongsTo(CatalogSourceList::class, 'catalog_source_list_id');
    }

    public function firstIntakeRun(): BelongsTo
    {
        return $this->belongsTo(CuratedProductIntakeRun::class, 'first_intake_run_id');
    }

    public function lastIntakeRun(): BelongsTo
    {
        return $this->belongsTo(CuratedProductIntakeRun::class, 'last_intake_run_id');
    }
}
