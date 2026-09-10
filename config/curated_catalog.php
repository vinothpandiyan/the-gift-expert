<?php

return [

    'schema_version' => 2,

    'allowed_schema_versions' => [1, 2],

    'max_items' => 250,

    'image_acquisition' => [

        'merchants' => [

            'amazon-in' => [
                'allowed_hosts' => [
                    'm.media-amazon.com',
                ],
            ],

        ],

        /*
         | Merchant-aware Amazon CDN normalization. Host matching is
         | conservative: Flipkart/Etsy/other merchant URLs are never rewritten.
         | Canonical long-edge is a source fetch target; local 1:1 processing
         | still applies after download.
         */
        'amazon' => [
            'canonical_host' => 'm.media-amazon.com',
            'canonical_token' => 'SL',
            'canonical_long_edge' => 1500,
            'min_acceptable_long_edge' => 1000,
            'max_long_edge' => 2000,
            'content_trim' => [
                'enabled' => true,
                'square_ratio_tolerance' => 0.02,
                'corner_color_tolerance' => 4,
                'background_color_tolerance' => 8,
                'minimum_background_channel' => 240,
                'minimum_uniform_border_ratio' => 0.995,
                'uniform_border_depth_ratio' => 0.015,
                'content_scan_step' => 2,
                'safety_margin_ratio' => 0.12,
                'minimum_safety_margin' => 48,
                'minimum_canvas_edge' => 600,
                'maximum_canvas_ratio' => 0.82,
            ],
            'hosts' => [
                'm.media-amazon.com',
                'images-na.ssl-images-amazon.com',
                'images-eu.ssl-images-amazon.com',
                'images-fe.ssl-images-amazon.com',
                'images-amazon.com',
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
    | Wishlist / source-list mappings
    |--------------------------------------------------------------------------
    |
    | Maps merchant wishlists and collections to operational kinds.
    | This is not taxonomy. Relationship slugs are classification hints only.
    | Prefer by_external_list_id when the Amazon (or other) list ID is known.
    |
    */

    'source_lists' => [

        'amazon-in' => [

            'by_external_list_id' => [
                // Populate when Amazon list IDs are known. Preferred over name matching.
            ],

            'by_normalized_name' => [
                'gifts-for-husband' => ['kind' => 'recipient_hint', 'relationship' => 'husband'],
                'gifts-for-boyfriend' => ['kind' => 'recipient_hint', 'relationship' => 'boyfriend'],
                'gifts-for-father' => ['kind' => 'recipient_hint', 'relationship' => 'father'],
                'gifts-for-brother' => ['kind' => 'recipient_hint', 'relationship' => 'brother'],
                'gifts-for-son' => ['kind' => 'recipient_hint', 'relationship' => 'son'],
                'gifts-for-wife' => ['kind' => 'recipient_hint', 'relationship' => 'wife'],
                'gifts-for-girlfriend' => ['kind' => 'recipient_hint', 'relationship' => 'girlfriend'],
                'gifts-for-mother' => ['kind' => 'recipient_hint', 'relationship' => 'mother'],
                'gifts-for-sister' => ['kind' => 'recipient_hint', 'relationship' => 'sister'],
                'gifts-for-daughter' => ['kind' => 'recipient_hint', 'relationship' => 'daughter'],
                'gifts-for-friends' => ['kind' => 'recipient_hint', 'relationship' => 'friends'],
                'gifts-for-parents' => ['kind' => 'recipient_hint', 'relationship' => 'parents'],
                'gifts-for-colleagues' => ['kind' => 'recipient_hint', 'relationship' => 'colleagues'],
                'gifts-for-boss' => ['kind' => 'recipient_hint', 'relationship' => 'boss'],
                'gifts-for-newlyweds' => ['kind' => 'recipient_hint', 'relationship' => 'newlyweds'],
                'gifts-for-grandparents' => ['kind' => 'recipient_hint', 'relationship' => 'grandparents'],
                '00-unclassified-gift-ideas' => ['kind' => 'unclassified_inbox'],
                'unclassified-gift-ideas' => ['kind' => 'unclassified_inbox'],
                '01-gift-ideas-q1-2026' => ['kind' => 'quarterly_archive'],
                'gift-ideas-q1-2026' => ['kind' => 'quarterly_archive'],
                '02-gift-ideas-q2-2026' => ['kind' => 'quarterly_archive'],
                'gift-ideas-q2-2026' => ['kind' => 'quarterly_archive'],
                '03-gift-ideas-q3-2026' => ['kind' => 'quarterly_archive'],
                'gift-ideas-q3-2026' => ['kind' => 'quarterly_archive'],
                '04-gift-ideas-q4-2026' => ['kind' => 'quarterly_archive'],
                'gift-ideas-q4-2026' => ['kind' => 'quarterly_archive'],
            ],

            'normalized_name_patterns' => [
                '/^(?:\d+-)?unclassified-gift-ideas$/' => ['kind' => 'unclassified_inbox'],
                '/^(?:\d+-)?gift-ideas-q[1-4]-\d{4}$/' => ['kind' => 'quarterly_archive'],
            ],

        ],

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

    'editorial_copy' => [
        'version' => 1,
    ],

    'seo' => [
        'version' => 1,
        'meta_title_max_length' => 60,
        'meta_description_max_length' => 160,
    ],

    /*
    |--------------------------------------------------------------------------
    | Taxonomy classification
    |--------------------------------------------------------------------------
    |
    | Version is an intentional policy/prompt/taxonomy generation. Bumping it
    | marks AI-managed drafts as eligible for reclassification. It is not a
    | timestamp. Thresholds are review-quality signals, not mathematical truth.
    |
    */

    'taxonomy_classification' => [
        'version' => 1,
        'thresholds' => [
            'primary_category_auto_accept' => 0.85,
            'gift_type_auto_accept' => 0.80,
            'optional_keep_min' => 0.60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Classification execution
    |--------------------------------------------------------------------------
    |
    | Bulk classification is sequential per artisan process / queue worker.
    | Raise queue worker count instead of building a custom pool. Values
    | greater than 1 are documentation for --queue worker sizing.
    |
    */

    'classification' => [
        'max_concurrency' => (int) env('CURATED_CLASSIFICATION_MAX_CONCURRENCY', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Initial bulk intake
    |--------------------------------------------------------------------------
    |
    | Directory commits refuse when malformed ASINs exceed these bounds.
    | Isolated per-product ASIN errors below the absolute cap may still ingest.
    |
    */

    'bulk_intake' => [
        'max_malformed_external_ids' => 5,
        'max_malformed_external_id_ratio' => 0.05,
        'min_occurrences_for_malformed_ratio' => 20,
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
