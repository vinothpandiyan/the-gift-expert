<?php

namespace App\Models;

use App\Enums\CatalogCandidateSourcingItemStatus;
use App\Enums\ProductAutomationReadiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogCandidateSourcingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'catalog_candidate_sourcing_run_id',
        'catalog_candidate_id',
        'merchant_id',
        'selected_offer',
        'enrichment',
        'product_id',
        'affiliate_link_id',
        'status',
        'readiness',
        'exception_codes',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogCandidateSourcingItemStatus::class,
            'readiness' => ProductAutomationReadiness::class,
            'selected_offer' => 'array',
            'enrichment' => 'array',
            'exception_codes' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CatalogCandidateSourcingRun::class, 'catalog_candidate_sourcing_run_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(CatalogCandidate::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
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
