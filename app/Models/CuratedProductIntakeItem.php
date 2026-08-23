<?php

namespace App\Models;

use App\Enums\CuratedProductIntakeItemOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuratedProductIntakeItem extends Model
{
    protected $fillable = [
        'curated_product_intake_run_id',
        'item_index',
        'external_product_id',
        'product_id',
        'affiliate_link_id',
        'outcome',
        'source_payload',
        'warnings',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => CuratedProductIntakeItemOutcome::class,
            'source_payload' => 'array',
            'warnings' => 'array',
            'item_index' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CuratedProductIntakeRun::class, 'curated_product_intake_run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function affiliateLink(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class);
    }
}
