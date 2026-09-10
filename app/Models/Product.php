<?php

namespace App\Models;

use App\Enums\EditorialOwnership;
use App\Enums\ProductStatus;
use App\Enums\SeoOwnership;
use App\Enums\TaxonomyClassificationStatus;
use App\Observers\ProductObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
        'editorial_ownership',
        'editorial_generation_version',
        'editorial_reviewed_at',
        'editorial_reviewed_by_user_id',
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
        'seo_ownership',
        'seo_generation_version',
        'seo_reviewed_at',
        'seo_reviewed_by_user_id',
        'published_at',
        'taxonomy_classification_status',
        'taxonomy_classified_at',
        'taxonomy_content_fingerprint',
        'taxonomy_relationship_hint_fingerprint',
        'taxonomy_classification_version',
        'taxonomy_review_reasons',
        'taxonomy_classification_warnings',
        'taxonomy_gap_suggestion',
        'taxonomy_gap_explanation',
        'taxonomy_reasoning',
        'taxonomy_classification_proposal',
        'taxonomy_proposal_pending',
        'taxonomy_approved_at',
        'taxonomy_approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'editorial_ownership' => EditorialOwnership::class,
            'editorial_generation_version' => 'integer',
            'editorial_reviewed_at' => 'datetime',
            'seo_ownership' => SeoOwnership::class,
            'seo_generation_version' => 'integer',
            'seo_reviewed_at' => 'datetime',
            'price_amount' => 'decimal:2',
            'compare_at_amount' => 'decimal:2',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
            'taxonomy_classification_status' => TaxonomyClassificationStatus::class,
            'taxonomy_classified_at' => 'datetime',
            'taxonomy_classification_version' => 'integer',
            'taxonomy_review_reasons' => 'array',
            'taxonomy_classification_warnings' => 'array',
            'taxonomy_reasoning' => 'array',
            'taxonomy_classification_proposal' => 'array',
            'taxonomy_proposal_pending' => 'boolean',
            'taxonomy_approved_at' => 'datetime',
        ];
    }

    public function taxonomyApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taxonomy_approved_by_user_id');
    }

    public function editorialReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editorial_reviewed_by_user_id');
    }

    public function seoReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seo_reviewed_by_user_id');
    }

    public function seoIsHumanOwned(): bool
    {
        return $this->seo_ownership === SeoOwnership::Human;
    }

    public function seoNeedsAiGeneration(): bool
    {
        if ($this->seoIsHumanOwned()) {
            return false;
        }

        return $this->seo_ownership !== SeoOwnership::Ai
            || ($this->seo_generation_version ?? 0) < (int) config('curated_catalog.seo.version', 1)
            || blank($this->meta_title)
            || blank($this->meta_description);
    }

    public function editorialCopyIsHumanOwned(): bool
    {
        return $this->editorial_ownership === EditorialOwnership::Human;
    }

    public function editorialCopyIsSourceOwned(): bool
    {
        return $this->editorial_ownership === EditorialOwnership::Source;
    }

    public function editorialCopyNeedsAiGeneration(): bool
    {
        if ($this->editorialCopyIsHumanOwned()) {
            return false;
        }

        return $this->editorial_ownership !== EditorialOwnership::Ai
            || ($this->editorial_generation_version ?? 0) < (int) config('curated_catalog.editorial_copy.version', 1);
    }

    public function taxonomyClassificationIsHumanLocked(): bool
    {
        $status = $this->taxonomy_classification_status;

        return $status instanceof TaxonomyClassificationStatus && $status->isHumanLocked();
    }

    public function taxonomyProposalIsPending(): bool
    {
        return (bool) $this->taxonomy_proposal_pending;
    }

    public function taxonomyClassificationIsPublishable(): bool
    {
        $status = $this->taxonomy_classification_status;

        return $status instanceof TaxonomyClassificationStatus && $status->isPublishable();
    }

    public function catalogProductSources(): HasManyThrough
    {
        return $this->hasManyThrough(CatalogProductSource::class, AffiliateLink::class);
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

    public function isPersonalized(): bool
    {
        $matchesSlug = fn ($record): bool => $record->slug === 'personalized-gifts';

        if ($this->relationLoaded('giftTypes') && $this->giftTypes->contains($matchesSlug)) {
            return true;
        }

        if ($this->relationLoaded('categories') && $this->categories->contains($matchesSlug)) {
            return true;
        }

        return false;
    }
}
