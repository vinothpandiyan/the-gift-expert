<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecommendationResult extends Model
{
    /**
     * Legacy match tiers. Snapshots stored before relevance groups existed carry only
     * `match_tier`; new results store `relevance_group` instead (see relevanceGroup()).
     */
    public const TIER_BEST = 1;

    public const TIER_GOOD = 2;

    public const TIER_RELATED = 3;

    /**
     * Relevance groups, best first. They are the primary ranking key.
     *
     * When interests are selected they mean:
     *   1  selected interest + every answered relationship / occasion signal
     *   2  selected interest + one of relationship / occasion
     *   3  selected interest only
     *   4  relationship + occasion, no selected interest
     *   5  other relaxed candidates
     *
     * Without selected interests only 1 (matches every answered signal) and 5 are used.
     */
    public const GROUP_BEST = 1;

    public const GROUP_STRONG_INTEREST = 2;

    public const GROUP_INTEREST_ONLY = 3;

    public const GROUP_RELATIONSHIP_OCCASION = 4;

    public const GROUP_RELAXED = 5;

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

    /**
     * Snapshots without `relevance_group` fall back to their legacy `match_tier`.
     */
    public function relevanceGroup(): int
    {
        $group = $this->score_breakdown['relevance_group'] ?? null;

        if ($group !== null) {
            return (int) $group;
        }

        return match ($this->matchTier()) {
            self::TIER_GOOD => self::GROUP_RELATIONSHIP_OCCASION,
            self::TIER_RELATED => self::GROUP_RELAXED,
            default => self::GROUP_BEST,
        };
    }

    /**
     * "Great matches": the selected interest(s) plus relationship and/or occasion
     * (or, without interests, every answered relationship / occasion signal).
     */
    public function isGreatMatchGroup(): bool
    {
        return $this->relevanceGroup() <= self::GROUP_STRONG_INTEREST;
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
