<?php

use App\CommercialSourcing\FakeCommercialOfferSearchProvider;
use App\CommercialSourcing\TavilyCommercialOfferSearchProvider;

return [

    'default_market' => 'IN',

    /*
    |--------------------------------------------------------------------------
    | Automation (deferred)
    |--------------------------------------------------------------------------
    |
    | Phase 19D.4 does not auto-publish. This flag remains unused until an
    | explicit future phase approves human-triggered or scheduled publication.
    |
    */

    'auto_publish' => false,

    'taxonomy_caps' => [
        'interests' => 3,
        'relationships' => 4,
        'occasions' => 4,
        'recipient_types' => 3,
        'professions' => 2,
        'gift_types' => 2,
        'categories' => 3,
    ],

    'enrichment' => [

        'api_key' => env('OPENAI_API_KEY'),

        'model' => env('OPENAI_MODEL'),

        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

        'timeout' => 45,

        'connect_timeout' => 5,

        'max_redirects' => 3,

        'temperature' => env('COMMERCIAL_SOURCING_ENRICHMENT_TEMPERATURE'),

        'max_output_tokens' => 2000,

        'max_evidence' => 8,

        'max_prompt_chars' => 24000,

        'snippet_max_length' => 400,

    ],

    'search' => [

        'provider' => env('COMMERCIAL_SOURCING_SEARCH_PROVIDER', 'tavily'),

        'max_queries_per_candidate' => 2,

        'max_results_per_query' => 8,

        'snippet_max_length' => 400,

        'max_query_length' => 400,

        'providers' => [

            'fake' => [
                'class' => FakeCommercialOfferSearchProvider::class,
                'fixture' => base_path('tests/Fixtures/commercial-sourcing/offers-fake.json'),
                'allowed_environments' => ['local', 'testing'],
            ],

            'tavily' => [
                'class' => TavilyCommercialOfferSearchProvider::class,
                'api_key' => env('TAVILY_API_KEY'),
                'base_url' => env('TAVILY_BASE_URL', 'https://api.tavily.com'),
                'timeout' => 20,
                'connect_timeout' => 5,
                'max_redirects' => 3,
            ],

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Partner merchants
    |--------------------------------------------------------------------------
    |
    | Keys MUST match merchants.slug. Actions resolve Merchant rows by slug
    | and never hardcode marketplace names. Disabled or unmatched entries are
    | ignored. Do not auto-create Merchant rows from this config.
    |
    */

    'merchants' => [

        'amazon-in' => [
            'enabled' => true,
            'priority' => 100,
            'markets' => ['IN'],
            'search_enabled' => true,
            'affiliate_enabled' => true,
            'domains' => ['amazon.in'],
            'image_policy_key' => 'amazon_associates',
            'external_id_strategy' => 'extractor',
            'external_id' => [
                'rules' => [
                    ['type' => 'path_regex', 'pattern' => '#/dp/([A-Z0-9]{10})(?:[/?]|$)#i'],
                    ['type' => 'path_regex', 'pattern' => '#/gp/product/([A-Z0-9]{10})(?:[/?]|$)#i'],
                    ['type' => 'query', 'param' => 'asin'],
                ],
            ],
            'url_fingerprint' => [
                'enabled' => false,
                'strip_query_params' => ['tag', 'ref', 'ref_', 'psc', 'th', 'linkCode', 'camp', 'creative', 'creativeASIN', 'ie', 'keywords', 'sr', 'qid', 'sprefix', 'crid', 'dib', 'dib_tag'],
            ],
            'affiliate_strategy' => 'query_param',
            'affiliate' => [
                'strategy' => 'query_param',
                'param' => env('COMMERCIAL_SOURCING_AMAZON_IN_AFFILIATE_PARAM'),
                'value' => env('COMMERCIAL_SOURCING_AMAZON_IN_AFFILIATE_VALUE'),
                'allowed_domains' => ['amazon.in'],
            ],
            'deny_path_patterns' => [
                '#^/s(/|$)#',
                '#/gp/search#',
                '#/gp/bestsellers#',
                '#/stores/#',
            ],
        ],

        'flipkart' => [
            'enabled' => true,
            'priority' => 90,
            'markets' => ['IN'],
            'search_enabled' => true,
            'affiliate_enabled' => true,
            'domains' => ['flipkart.com'],
            'image_policy_key' => 'fake',
            'external_id_strategy' => 'extractor',
            'external_id' => [
                'rules' => [
                    ['type' => 'path_regex', 'pattern' => '#/(?:p|dl)/([A-Za-z0-9]+)(?:[/?]|$)#'],
                    ['type' => 'query', 'param' => 'pid'],
                ],
            ],
            'url_fingerprint' => [
                'enabled' => false,
                'strip_query_params' => ['marketplace', 'lid', 'fm', 'iid', 'ppt', 'ppn', 'ssid', 'otracker', 'cid'],
            ],
            'affiliate_strategy' => 'manual',
            'affiliate' => [
                'strategy' => 'manual',
            ],
            'deny_path_patterns' => [
                '#^/search#',
                '#^/q/#',
            ],
        ],

        'myntra' => [
            'enabled' => false,
            'priority' => 70,
            'markets' => ['IN'],
            'search_enabled' => true,
            'affiliate_enabled' => false,
            'domains' => ['myntra.com'],
            'image_policy_key' => 'fake',
            'external_id_strategy' => 'url_fingerprint',
            'external_id' => [
                'rules' => [],
            ],
            'url_fingerprint' => [
                'enabled' => true,
                'strip_query_params' => ['utm_source', 'utm_medium', 'utm_campaign'],
            ],
            'affiliate_strategy' => 'manual',
            'affiliate' => [
                'strategy' => 'manual',
            ],
            'deny_path_patterns' => [
                '#^/shop/#',
                '#^/search#',
            ],
        ],

    ],

];
