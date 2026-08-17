<?php

use App\Import\FakeCatalogProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Catalog Import Providers
    |--------------------------------------------------------------------------
    |
    | Provider keys match merchants.affiliate_network. Unknown keys must fail
    | rather than guessing. Local image acquisition is allowed only when both
    | store_images and transform_images are true. Phase 16 processing still
    | applies to stored files.
    |
    */

    'providers' => [

        'fake' => [
            'class' => FakeCatalogProvider::class,
            'fixture' => base_path('tests/Fixtures/import/catalog.json'),
            'policy' => [
                'store_images' => true,
                'transform_images' => true,
                'max_images' => 5,
            ],
        ],

        'amazon_associates' => [
            'class' => null,
            'policy' => [
                'store_images' => false,
                'transform_images' => false,
                'max_images' => 5,
            ],
        ],

    ],

    'http' => [
        'timeout' => 10,
        'connect_timeout' => 5,
        'max_redirects' => 3,
    ],

];
