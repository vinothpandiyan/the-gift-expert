<?php

namespace App\Models;

use App\Enums\NavigationSectionAppearance;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NavigationSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'navigation_menu_id',
        'heading',
        'appearance',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'appearance' => NavigationSectionAppearance::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(NavigationMenu::class, 'navigation_menu_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(NavigationLink::class)->orderBy('sort_order');
    }
}
