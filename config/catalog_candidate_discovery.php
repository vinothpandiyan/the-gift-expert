<?php

use App\CatalogCandidate\Discovery\FakeCatalogCandidateDiscoveryProvider;

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

    ],

];
