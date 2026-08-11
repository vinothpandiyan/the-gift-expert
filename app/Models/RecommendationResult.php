<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecommendationResult extends Model
{
    protected $fillable = [
        'recommendation_session_id',
        'product_id',
        'score',
        'rank',
        'score_breakdown',
        'explanation',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'rank' => 'integer',
            'score_breakdown' => 'array',
        ];
    }

    public function recommendationSession(): BelongsTo
    {
        return $this->belongsTo(RecommendationSession::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function affiliateClicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class);
    }
}
