<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxonomySlugRedirect extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'taxonomy',
        'from_slug',
        'to_slug',
    ];
}
