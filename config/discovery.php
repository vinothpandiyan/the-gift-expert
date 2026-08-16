<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Discovery URL Routes
    |--------------------------------------------------------------------------
    |
    | Public URL path templates for gift discovery pages. Prefixes and patterns
    | live here so they can be changed without altering taxonomy entities or
    | the database schema. Placeholders use {name} syntax.
    |
    */

    'routes' => [
        'gift.show' => '/gifts/{slug}',
        'gift_ideas.index' => '/gift-ideas',
        'gift_ideas.category' => '/gift-ideas/{full_path}',
        'occasion.show' => '/occasions/{slug}',
        'relationship.show' => '/gifts-for/{slug}',
        'recipient_type.show' => '/recipients/{slug}',
        'interest.show' => '/interests/{slug}',
        'profession.show' => '/professions/{slug}',
        'gift_type.show' => '/gift-types/{slug}',
        'finder.show' => '/find-a-gift',
        'finder.results' => '/find-a-gift/results/{uuid}',
        'affiliate.out' => '/out/{uuid}',
        'seo_landing.show' => '/{slug}',
        'sitemap.index' => '/sitemap.xml',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reserved First-Segment Slugs
    |--------------------------------------------------------------------------
    |
    | A landing-page slug is rejected only when it equals one of these values
    | exactly. Compound slugs such as gifts-for-boyfriend remain allowed.
    |
    */

    'reserved_prefixes' => [
        'gifts',
        'gift-ideas',
        'gifts-for',
        'occasions',
        'recipients',
        'interests',
        'professions',
        'gift-types',
        'find-a-gift',
        'out',
        'admin',
        'up',
        'livewire',
        'build',
        'storage',
    ],

];
