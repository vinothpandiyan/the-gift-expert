<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecommendationResult extends Model
{
    /** Matches every answered signal (relationship, occasion and, if chosen, an interest). */
    public const TIER_BEST = 1;

    /** Matches relationship and occasion but none of the chosen interests. */
    public const TIER_GOOD = 2;

    /** Matches at least one of relationship, occasion or a chosen interest. */
    public const TIER_RELATED = 3;

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

    /**
     * Results stored before tiers existed carry no `match_tier` and count as best.
     */
    public function matchTier(): int
    {
        return (int) ($this->score_breakdown['match_tier'] ?? self::TIER_BEST);
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
