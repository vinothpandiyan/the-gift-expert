<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoLandingPageRedirect extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'from_slug',
        'to_slug',
        'seo_landing_page_id',
    ];

    public function seoLandingPage(): BelongsTo
    {
        return $this->belongsTo(SeoLandingPage::class);
    }
}
