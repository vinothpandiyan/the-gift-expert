<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Observers\ProductObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(ProductObserver::class)]
class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'short_description',
        'description',
        'brand',
        'sku',
        'status',
        'price_amount',
        'price_currency',
        'compare_at_amount',
        'is_featured',
        'meta_title',
        'meta_description',
        'canonical_url',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'price_amount' => 'decimal:2',
            'compare_at_amount' => 'decimal:2',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function affiliateLinks(): HasMany
    {
        return $this->hasMany(AffiliateLink::class);
    }

    public function importRunItems(): HasMany
    {
        return $this->hasMany(ImportRunItem::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product')
            ->withPivot('is_primary');
    }

    public function occasions(): BelongsToMany
    {
        return $this->belongsToMany(Occasion::class, 'occasion_product')
            ->withPivot('created_at');
    }

    public function relationships(): BelongsToMany
    {
        return $this->belongsToMany(Relationship::class, 'relationship_product')
            ->withPivot('created_at');
    }

    public function recipientTypes(): BelongsToMany
    {
        return $this->belongsToMany(RecipientType::class, 'recipient_type_product')
            ->withPivot('created_at');
    }

    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class, 'interest_product')
            ->withPivot('created_at');
    }

    public function professions(): BelongsToMany
    {
        return $this->belongsToMany(Profession::class, 'profession_product')
            ->withPivot('created_at');
    }

    public function giftTypes(): BelongsToMany
    {
        return $this->belongsToMany(GiftType::class, 'gift_type_product')
            ->withPivot('created_at');
    }

    public function recommendationResults(): HasMany
    {
        return $this->hasMany(RecommendationResult::class);
    }

    public function sourcingItems(): HasMany
    {
        return $this->hasMany(CatalogCandidateSourcingItem::class);
    }

    public function latestPromotedSourcingItem(): HasOne
    {
        return $this->hasOne(CatalogCandidateSourcingItem::class)->latestOfMany();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', ProductStatus::Published)
            ->whereNull('deleted_at');
    }
}
