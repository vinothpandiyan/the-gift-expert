<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BudgetRange extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'min_amount',
        'max_amount',
        'currency',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
