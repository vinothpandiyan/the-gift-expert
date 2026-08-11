<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Optional Dimension Filtering
    |--------------------------------------------------------------------------
    |
    | When true, selecting an optional finder dimension (relationship, recipient
    | type, interest, profession, gift type) excludes products not tagged with
    | that dimension. When false, untagged products remain with zero score.
    |
    */

    'optional_dimensions_filter_strict' => true,

    /*
    |--------------------------------------------------------------------------
    | Result Limits
    |--------------------------------------------------------------------------
    */

    'top_n' => 12,

    'max_interests' => 3,

    /*
    |--------------------------------------------------------------------------
    | Scoring Weights
    |--------------------------------------------------------------------------
    |
    | Deterministic, explainable weights for the MVP recommendation engine.
    | Budget is a hard filter only and is not scored.
    |
    */

    'weights' => [
        'occasion_match' => 25,
        'relationship_match' => 15,
        'recipient_type_match' => 15,
        'interest_match' => 10,
        'interest_match_max' => 30,
        'profession_match' => 20,
        'gift_type_match' => 15,
        'featured_boost' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tie Breakers
    |--------------------------------------------------------------------------
    |
    | Applied in order when scores are equal: higher score first, then lower
    | price, then newer published_at, then lower product id.
    |
    */

    'tie_breakers' => [
        'score',
        'price_amount',
        'published_at',
        'id',
    ],

];
