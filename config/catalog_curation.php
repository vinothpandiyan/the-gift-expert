<?php

return [
    'dimensions' => [
        'relationships',
        'occasions',
        'interests',
        'gift_types',
    ],

    'versions' => [
        'semantic_evaluator' => '3',
        'prompt' => '3',
        'scoring' => '3',
        'context' => '3',
    ],

    'limits' => [
        'gift_intents' => 3,
        'concept_key_candidate' => 120,
        'concept_label_candidate' => 160,
        'why_this_gift' => 240,
        'strengths' => 5,
    ],

    'gift_score' => [
        'semantic_components' => [
            'recipient_desirability' => 20,
            'thoughtfulness_emotional_potential' => 15,
            'uniqueness' => 15,
            'value_for_money' => 15,
            'visual_gifting_appeal' => 10,
            'practical_usefulness' => 10,
            'social_media_shareability' => 5,
        ],
        'database_components' => [
            'product_vendor_confidence' => 10,
        ],
        'product_vendor_confidence' => [
            'active_offer' => 4,
            'primary_image' => 2,
            'provenance' => 2,
            'recent_last_seen' => 2,
        ],
    ],

    'catalog_value' => [
        'factors' => [
            'saturation_novelty' => 35,
            'differentiation' => 20,
            'budget_gap' => 15,
            'taxonomy_gap' => 15,
            'intents' => 10,
            'niche' => 5,
        ],
        'saturation_scores' => [0 => 35, 1 => 28, 2 => 21, 3 => 14, 4 => 7, 5 => 0],
        'differentiation_scores' => [
            'strong' => 20,
            'medium' => 13,
            'weak' => 6,
            'none' => 0,
        ],
        'intent_peer_scores' => [0 => 10, 1 => 8, 2 => 6, 3 => 4, 5 => 2],
        'niche_scores' => [
            'strong' => [0 => 5, 1 => 4, 3 => 3],
            'medium' => [0 => 4, 1 => 3, 3 => 2],
            'weak' => [0 => 2, 1 => 1, 3 => 0],
            'none' => [0 => 0],
        ],
        'relative_candidate' => [
            'concept_exact_max' => 22,
            'concept_saturation_count' => 6,
            'taxonomy_assignment_weights' => [0.65, 0.35],
            'dimension_weights' => [0.50, 0.30, 0.20],
            'context_weights' => [0.50, 0.30, 0.20],
            'intent_weights' => [0.65, 0.35],
            'budget_band_weight' => 0.25,
            'budget_context_weight' => 0.75,
            'price_only_cap' => 4,
            'shared_scarcity_cap' => 40,
            'differentiation_ranges' => [
                'strong' => [16, 20],
                'medium' => [10, 14],
                'weak' => [3, 7],
                'none' => [0, 0],
            ],
            'niche_ranges' => [
                'strong' => [4, 5],
                'medium' => [2, 4],
                'weak' => [1, 2],
                'none' => [0, 0],
            ],
            'budget_bands' => [
                ['slug' => 'under-500', 'min' => null, 'max' => 499.99],
                ['slug' => '500-1000', 'min' => 500, 'max' => 999.99],
                ['slug' => '1000-2500', 'min' => 1000, 'max' => 2499.99],
                ['slug' => '2500-5000', 'min' => 2500, 'max' => 4999.99],
                ['slug' => '5000-10000', 'min' => 5000, 'max' => 9999.99],
                ['slug' => '10000-plus', 'min' => 10000, 'max' => null],
            ],
        ],
    ],

    'scoring_bands' => [
        'strong' => 80,
        'moderate' => 65,
        'weak' => 0,
    ],

    'thresholds' => [
        'human_review_gift_score' => 65,
        'human_review_catalog_value_score' => 50,
        'feature_gift_score' => 80,
        'feature_catalog_value_score' => 70,
        'keep_gift_score' => 65,
        'keep_catalog_value_score' => 60,
        'keep_niche_gift_score' => 55,
        'keep_niche_catalog_value_score' => 70,
        'remove_gift_score' => 45,
        'remove_catalog_value_score' => 35,
        'replace_gift_score' => 55,
        'replace_catalog_value_score' => 50,
        'weak_value_for_money' => 8,
        'weak_product_vendor_confidence' => 6,
        'stale_last_seen_days' => 60,
        'concept_oversaturated_peers' => 5,
        'possible_duplicate_peers' => 1,
    ],

    'price_bands' => [
        ['slug' => 'under-1000', 'min' => null, 'max' => 999.99],
        ['slug' => '1000-2499', 'min' => 1000, 'max' => 2499.99],
        ['slug' => '2500-4999', 'min' => 2500, 'max' => 4999.99],
        ['slug' => '5000-plus', 'min' => 5000, 'max' => null],
    ],

    'aliases' => [
        'concepts' => [
            'portable-rgb-bluetooth-speaker' => [
                'key' => 'portable-bluetooth-speaker',
                'label' => 'Portable Bluetooth Speaker',
            ],
            'waterproof-mini-bluetooth-speaker' => [
                'key' => 'portable-bluetooth-speaker',
                'label' => 'Portable Bluetooth Speaker',
            ],
            'portable-party-speaker' => [
                'key' => 'portable-bluetooth-speaker',
                'label' => 'Portable Bluetooth Speaker',
            ],
            'green-leather-everyday-wallet' => [
                'key' => 'wallet',
                'label' => 'Wallet',
            ],
            'premium-bifold-wallet' => [
                'key' => 'wallet',
                'label' => 'Wallet',
            ],
            'rfid-leather-wallet' => [
                'key' => 'wallet',
                'label' => 'Wallet',
            ],
            'adjustable-fitness-skipping-rope' => [
                'key' => 'skipping-rope',
                'label' => 'Skipping Rope',
            ],
            'adjustable-counting-cardio-rope' => [
                'key' => 'skipping-rope',
                'label' => 'Skipping Rope',
            ],
        ],
        'relationships' => [],
        'occasions' => [],
        'interests' => [],
        'gift_types' => [],
    ],

    'weak_why_phrases' => [
        'great gift',
        'perfect gift',
        'ideal gift',
        'good gift',
        'suitable gift',
        'thoughtful gift',
        'something special',
    ],

    'human_curation' => [
        'peer_comparison_limit' => 8,
        'weak_differentiation_max' => 6,
        'integrity_issue_codes' => [
            'semantic_response_invalid',
            'unresolved_taxonomy_label',
            'missing_current_assignment_evaluation',
        ],
        'material_taxonomy_issue_codes' => [
            'relationship_overclassification',
            'occasion_overclassification',
            'interest_mismatch',
            'gift_type_mismatch',
            'taxonomy_conflict',
            'hard_taxonomy_conflict',
            'missing_primary_category',
        ],
        'evidence_issue_codes' => [
            'missing_commerce_evidence',
            'weak_product_confidence',
        ],
        'feature_density_warning_share' => 0.8,
    ],
];
