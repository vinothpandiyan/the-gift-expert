<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AffiliateClick extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid',
        'affiliate_link_id',
        'product_id',
        'recommendation_session_id',
        'recommendation_result_id',
        'ip_hash',
        'user_agent',
        'referrer_url',
        'landing_path',
        'clicked_at',
    ];

    protected function casts(): array
    {
        return [
            'clicked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AffiliateClick $click): void {
            if (blank($click->uuid)) {
                $click->uuid = (string) Str::uuid();
            }

            if ($click->clicked_at === null) {
                $click->clicked_at = now();
            }
        });
    }

    public function affiliateLink(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recommendationSession(): BelongsTo
    {
        return $this->belongsTo(RecommendationSession::class);
    }

    public function recommendationResult(): BelongsTo
    {
        return $this->belongsTo(RecommendationResult::class);
    }
}
