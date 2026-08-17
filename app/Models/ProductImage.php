<?php

namespace App\Models;

use App\Observers\ProductImageObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[ObservedBy(ProductImageObserver::class)]
class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'disk',
        'path',
        'alt_text',
        'sort_order',
        'is_primary',
        'source_url',
        'content_hash',
        'acquired_at',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
            'acquired_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk ?: (string) config('media.product_images.disk', 'public'))->url($this->path);
    }
}
