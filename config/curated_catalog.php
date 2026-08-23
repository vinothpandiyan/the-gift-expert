<?php

return [

    'schema_version' => 1,

    'max_items' => 200,

    'max_items_per_commit' => 25,

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

];
