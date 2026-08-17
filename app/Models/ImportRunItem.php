<?php

namespace App\Models;

use App\Enums\ImportRunItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRunItem extends Model
{
    protected $fillable = [
        'import_run_id',
        'external_product_id',
        'product_id',
        'affiliate_link_id',
        'status',
        'error',
        'source_payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportRunItemStatus::class,
            'source_payload' => 'array',
        ];
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
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
