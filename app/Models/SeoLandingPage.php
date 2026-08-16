<?php

namespace App\Models;

use App\Enums\SeoLandingPageStatus;
use App\Observers\SeoLandingPageObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(SeoLandingPageObserver::class)]
class SeoLandingPage extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'heading',
        'intro_content',
        'body_content',
        'faq_content',
        'status',
        'is_indexable',
        'include_in_sitemap',
        'meta_title',
        'meta_description',
        'canonical_url',
        'occasion_id',
        'relationship_id',
        'recipient_type_id',
        'profession_id',
        'gift_type_id',
        'category_id',
        'budget_range_id',
        'sort_order',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SeoLandingPageStatus::class,
            'is_indexable' => 'boolean',
            'include_in_sitemap' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function occasion(): BelongsTo
    {
        return $this->belongsTo(Occasion::class);
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function budgetRange(): BelongsTo
    {
        return $this->belongsTo(BudgetRange::class);
    }

    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class, 'seo_landing_page_interests')
            ->withPivot('created_at');
    }

    public function slugRedirects(): HasMany
    {
        return $this->hasMany(SeoLandingPageRedirect::class);
    }
}
