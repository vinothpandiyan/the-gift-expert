<?php

use App\CatalogCandidate\Discovery\FakeCatalogCandidateDiscoveryProvider;
use App\CatalogCandidate\Discovery\TavilyCatalogCandidateSearchProvider;
use App\CatalogCandidate\Discovery\WebResearchCatalogCandidateDiscoveryProvider;

return [

    'provider' => env('CATALOG_CANDIDATE_DISCOVERY_PROVIDER', 'fake'),

    'max_candidates' => 20,

    'default_market' => 'IN',

    'default_freshness_days' => 30,

    'max_freshness_days' => 365,

    'providers' => [

        'fake' => [
            'class' => FakeCatalogCandidateDiscoveryProvider::class,
            'fixture' => base_path('tests/Fixtures/catalog-candidates/discovery-fake.json'),
            'allowed_environments' => ['local', 'testing'],
        ],

        'web_research' => [
            'class' => WebResearchCatalogCandidateDiscoveryProvider::class,
        ],

    ],

    'search' => [

        'provider' => env('CATALOG_CANDIDATE_SEARCH_PROVIDER', 'tavily'),

        'max_queries_per_brief' => 3,

        'max_results_per_query' => 8,

        'snippet_max_length' => 400,

        'max_query_length' => 400,

        'providers' => [

            'tavily' => [
                'class' => TavilyCatalogCandidateSearchProvider::class,
                'api_key' => env('TAVILY_API_KEY'),
                'base_url' => env('TAVILY_BASE_URL', 'https://api.tavily.com'),
                'timeout' => 20,
                'connect_timeout' => 5,
                'max_redirects' => 3,
            ],

        ],

    ],

    'synthesis' => [

        'provider' => env('CATALOG_CANDIDATE_SYNTHESIS_PROVIDER', 'openai'),

        'model' => env('OPENAI_MODEL'),

        'api_key' => env('OPENAI_API_KEY'),

        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

        'timeout' => 45,

        'connect_timeout' => 5,

        'max_redirects' => 3,

        'temperature' => env('CATALOG_CANDIDATE_SYNTHESIS_TEMPERATURE'),

        'max_output_tokens' => 4000,

        'max_sources' => 20,

        'max_prompt_chars' => 24000,

    ],

];
