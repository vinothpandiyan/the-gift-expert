<?php

return [

    'schema_version' => 1,

    'max_items' => 200,

    'image_acquisition' => [

        'merchants' => [

            'amazon-in' => [
                'allowed_hosts' => [
                    'm.media-amazon.com',
                ],
            ],

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Supported curated intake merchants
    |--------------------------------------------------------------------------
    |
    | Keys MUST match merchants.slug. Merchant-specific validation references
    | commercial_sourcing.merchants for domains and external ID rules.
    |
    */

    'merchants' => [

        'amazon-in' => [
            'enabled' => true,
            'default_currency' => 'INR',
            'asin_pattern' => '/^[A-Z0-9]{10}$/i',
        ],

    ],

    'availability_values' => [
        'in_stock',
        'out_of_stock',
        'unavailable',
        'unknown',
    ],

    'curation_groups' => [
        'men',
        'women',
        'unspecified',
    ],

    /*
    |--------------------------------------------------------------------------
    | Enrichment
    |--------------------------------------------------------------------------
    |
    | API credentials and HTTP limits reuse commercial_sourcing.enrichment.
    | Only curated-specific overrides belong here.
    |
    */

    'enrichment' => [
        'max_prompt_chars' => 24000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Background sync observability
    |--------------------------------------------------------------------------
    |
    | Seconds after dispatch with zero processed items before the UI suggests
    | a queue worker may not be running.
    |
    */

    'sync' => [
        'worker_wait_seconds' => 10,
    ],

];
