<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Publication Requirements
    |--------------------------------------------------------------------------
    |
    | Application-level rules enforced when publishing a product (gift).
    | These are not database constraints.
    |
    */

    'requirements' => [
        'name' => true,
        'slug' => true,
        'image' => true,
        'active_affiliate_link' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Publication Warnings
    |--------------------------------------------------------------------------
    |
    | Non-blocking warnings surfaced during publish when values are missing.
    |
    */

    'warnings' => [
        'price_amount' => true,
        'primary_category' => true,
    ],

];
