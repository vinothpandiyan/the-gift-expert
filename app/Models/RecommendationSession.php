<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class RecommendationSession extends Model
{
    protected $fillable = [
        'uuid',
        'occasion_id',
        'budget_range_id',
        'relationship_id',
        'recipient_type_id',
        'profession_id',
        'gift_type_id',
        'ip_hash',
        'user_agent',
        'referrer_url',
    ];

    protected static function booted(): void
    {
        static::creating(function (RecommendationSession $session): void {
            if (blank($session->uuid)) {
                $session->uuid = (string) Str::uuid();
            }
        });
    }

    public function occasion(): BelongsTo
    {
        return $this->belongsTo(Occasion::class);
    }

    public function budgetRange(): BelongsTo
    {
        return $this->belongsTo(BudgetRange::class);
    }

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }

    public function recipientType(): BelongsTo
    {
        return $this->belongsTo(RecipientType::class);
    }

    public function profession(): BelongsTo
    {
        return $this->belongsTo(Profession::class);
    }

    public function giftType(): BelongsTo
    {
        return $this->belongsTo(GiftType::class);
    }

    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class, 'recommendation_session_interests')
            ->withPivot('created_at');
    }

    public function results(): HasMany
    {
        return $this->hasMany(RecommendationResult::class);
    }

    public function affiliateClicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class);
    }
}
