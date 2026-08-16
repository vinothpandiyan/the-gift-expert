<?php

namespace App\Models;

use App\Enums\NavigationItemType;
use App\Enums\NavigationLinkType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NavigationMenu extends Model
{
    use HasFactory;

    protected $fillable = [
        'label',
        'slug',
        'item_type',
        'sort_order',
        'is_active',
        'link_type',
        'linkable_id',
        'route_key',
        'url',
        'opens_in_new_tab',
    ];

    protected function casts(): array
    {
        return [
            'item_type' => NavigationItemType::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'link_type' => NavigationLinkType::class,
            'linkable_id' => 'integer',
            'opens_in_new_tab' => 'boolean',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(NavigationSection::class)->orderBy('sort_order');
    }
}
