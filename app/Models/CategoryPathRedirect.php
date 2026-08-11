<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryPathRedirect extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'from_path',
        'to_path',
    ];
}
