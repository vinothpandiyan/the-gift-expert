<?php

namespace App\Models;

use App\Enums\NavigationLinkType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NavigationLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'navigation_section_id',
        'label',
        'link_type',
        'linkable_id',
        'route_key',
        'url',
        'opens_in_new_tab',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'link_type' => NavigationLinkType::class,
            'linkable_id' => 'integer',
            'opens_in_new_tab' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(NavigationSection::class, 'navigation_section_id');
    }
}
