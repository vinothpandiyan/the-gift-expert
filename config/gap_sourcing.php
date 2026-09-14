<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Targeted catalog gap sourcing
    |--------------------------------------------------------------------------
    |
    | Sourcing triage only. These heuristics must not become evaluator v4.
    | Discovery never creates Product rows, wishlists, publications, or archives.
    |
    */

    'default_candidates_path' => 'docs/operator-tools/gap-sourcing/22d3-discovered-candidates.json',

    'primary_gaps' => [
        'father' => [
            'label' => 'Father',
            'priority' => 1,
            'dimension' => 'relationships',
            'names' => ['Father'],
        ],
        'experience_gifts' => [
            'label' => 'Experience Gifts',
            'priority' => 2,
            'dimension' => 'gift_types',
            'names' => ['Experience Gifts'],
        ],
        'mothers_day' => [
            'label' => "Mother's Day",
            'priority' => 3,
            'dimension' => 'occasions',
            'names' => ["Mother's Day"],
        ],
        'pet_parent' => [
            'label' => 'Pet Parent',
            'priority' => 4,
            'dimension' => 'interests',
            'names' => ['Pet Parent'],
        ],
        'eco_conscious' => [
            'label' => 'Eco-Conscious',
            'priority' => 5,
            'dimension' => 'interests',
            'names' => ['Eco-Conscious'],
        ],
    ],

    'secondary_gaps' => [
        'parents' => [
            'label' => 'Parents',
            'dimension' => 'relationships',
            'names' => ['Parents'],
        ],
        'grandparents' => [
            'label' => 'Grandparents',
            'dimension' => 'relationships',
            'names' => ['Grandparents'],
        ],
        'baby_shower' => [
            'label' => 'Baby Shower',
            'dimension' => 'occasions',
            'names' => ['Baby Shower'],
        ],
        'get_well_soon' => [
            'label' => 'Get Well Soon',
            'dimension' => 'occasions',
            'names' => ['Get Well Soon'],
        ],
    ],

    'publication_backlog_examples' => [
        112 => ['Gift Cards', 'Digital / Instant Gifts'],
        64 => ['Boss'],
        158 => ['Raksha Bandhan'],
        189 => ['Gardening'],
    ],

    'keep_family_decisions' => ['keep', 'feature', 'keep_niche'],

    'price_bands' => [
        ['slug' => 'under-500', 'label' => 'under ₹500', 'min' => 0, 'max' => 499.99],
        ['slug' => '500-1000', 'label' => '₹500–₹1,000', 'min' => 500, 'max' => 999.99],
        ['slug' => '1000-2500', 'label' => '₹1,000–₹2,500', 'min' => 1000, 'max' => 2499.99],
        ['slug' => '2500-5000', 'label' => '₹2,500–₹5,000', 'min' => 2500, 'max' => 4999.99],
        ['slug' => '5000-10000', 'label' => '₹5,000–₹10,000', 'min' => 5000, 'max' => 9999.99],
        ['slug' => '10000-plus', 'label' => '₹10,000+', 'min' => 10000, 'max' => null],
    ],

    'blocked_generic_concepts' => [
        'wallet',
        'belt',
        'mug',
        'coffee-mug-gift-set',
        'mens-grooming-kit',
        'mens-grooming-gift-set',
        'keychain',
        'generic-keychain',
    ],

    'father_search_profiles' => [
        'practical',
        'tech',
        'fitness',
        'travel',
        'music',
        'gardening',
        'spirituality',
        'premium',
        'sentimental',
        'personalised',
    ],

    'wishlist_eligible_merchants' => ['amazon-in'],

    'amazon_domains' => ['amazon.in'],

];
