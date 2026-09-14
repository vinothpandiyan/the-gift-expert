<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Publication Requirements
    |--------------------------------------------------------------------------
    |
    | Application-level rules enforced when publishing a product (gift).
    | These are not database constraints.
    |
    */

    'requirements' => [
        'name' => true,
        'slug' => true,
        'image' => true,
        'active_affiliate_link' => true,
        'primary_category' => true,
        'classification_status' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Publication Warnings
    |--------------------------------------------------------------------------
    |
    | Non-blocking warnings surfaced during publish when values are missing.
    |
    */

    'warnings' => [
        'price_amount' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Launch publication
    |--------------------------------------------------------------------------
    |
    | Editorial eligibility for a first launch cohort. Technical readiness
    | remains AssessProductPublicationRequirementsAction / PublishProductAction.
    |
    */

    'launch' => [
        'concept_cluster_warning_threshold' => 3,
        'sparse_landing_page_count' => 2,
    ],

];
