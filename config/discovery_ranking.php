<?php

return [

    'enabled' => true,

    'per_page' => 12,

    'candidate_pool_max' => 500,

    'debug' => false,

    'weights' => [
        'relationship_match' => 20,
        'occasion_match' => 20,
        'interest_match' => 8,
        'interest_match_max' => 24,
        'recipient_type_match' => 15,
        'profession_match' => 15,
        'gift_type_match' => 15,
        'primary_category_match' => 12,
        'secondary_category_match' => 4,
        'featured' => 5,
        'price_present' => 2,
    ],

    'breadth' => [
        'threshold' => 4,
        'per_tag' => 1,
        'max_penalty' => 5,
    ],

    'diversity' => [
        'enabled' => true,
        'top_window' => 12,
        'max_same_primary_category_in_window' => 3,
        'max_consecutive_same_primary_category' => 2,
        'max_score_gap_for_diversity_swap' => 15,
        'relax_when_insufficient' => true,
    ],

    'surfaces' => [
        'relationship' => [
            'breadth_dimension' => 'relationships',
        ],
        'occasion' => [
            'breadth_dimension' => 'occasions',
        ],
        'recipient_type' => [
            'breadth_dimension' => null,
        ],
        'interest' => [
            'breadth_dimension' => null,
        ],
        'profession' => [
            'breadth_dimension' => null,
        ],
        'gift_type' => [
            'breadth_dimension' => null,
        ],
        'category' => [
            'breadth_dimension' => null,
        ],
        'seo_landing' => [
            'breadth_dimension' => null,
        ],
        'gift_detail' => [
            'breadth_dimension' => null,
        ],
        'gift_ideas' => [
            'breadth_dimension' => null,
        ],
    ],

];
