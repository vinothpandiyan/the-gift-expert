<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gap thresholds
    |--------------------------------------------------------------------------
    */

    'gaps' => [
        'minimum_count' => 3,
        'composite_minimum_count' => 2,
    ],

    'max_reported_gaps' => 20,

    'target_tolerance_percent' => 0,

    /*
    |--------------------------------------------------------------------------
    | Optional budget target percentages (keyed by BudgetRange slug)
    |--------------------------------------------------------------------------
    |
    | Example:
    | 'under-500' => 20,
    | '500-1000' => 25,
    |
    | Leave empty to report counts only.
    |
    */

    'budget_target_percentages' => [],

    /*
    |--------------------------------------------------------------------------
    | Composite coverage definitions
    |--------------------------------------------------------------------------
    |
    | Explicit dimension pairs only. category×budget uses primary category.
    |
    */

    'composites' => [
        ['key' => 'relationship_budget', 'dimensions' => ['relationship', 'budget_range']],
        ['key' => 'occasion_budget', 'dimensions' => ['occasion', 'budget_range']],
        ['key' => 'category_budget', 'dimensions' => ['category', 'budget_range']],
        ['key' => 'relationship_occasion', 'dimensions' => ['relationship', 'occasion']],
        ['key' => 'relationship_interest', 'dimensions' => ['relationship', 'interest']],
        ['key' => 'gift_type_budget', 'dimensions' => ['gift_type', 'budget_range']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quality gap signals (missing taxonomy / unpriced)
    |--------------------------------------------------------------------------
    |
    | Stronger catalog-quality signals become CoverageGap entries when enabled.
    | Optional dimensions (profession, gift_type, interest, recipient_type) are
    | reported in MissingTaxonomySummary only unless listed below.
    |
    */

    'quality_gaps' => [
        'unpriced' => true,
        'no_primary_category' => true,
        'no_category' => true,
        'no_relationship' => true,
        'no_occasion' => true,
    ],

    'missing_taxonomy_gap_dimensions' => [
        // 'profession', 'gift_type', 'interest', 'recipient_type'
    ],

];
