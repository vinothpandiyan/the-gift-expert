<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Result Limits
    |--------------------------------------------------------------------------
    |
    | The Gift Finder ranks gifts rather than filtering them away. Candidates
    | are retrieved in relaxation stages (see GenerateRecommendationsAction)
    | until at least `top_n` are available, then ranked and truncated.
    |
    */

    'top_n' => 12,

    'max_interests' => 5,

    /*
    |--------------------------------------------------------------------------
    | Scoring Weights
    |--------------------------------------------------------------------------
    |
    | Deterministic, explainable weights for the MVP recommendation engine.
    | Budget and RecipientGender are hard eligibility filters only and are not scored.
    |
    | Interests are a soft ranking signal. Each matched interest adds
    | `interest_match` until `interest_match_max` is reached; matches beyond that
    | add only `interest_match_extra` each, so a 4th/5th selected interest
    | differentiates gifts slightly without outweighing occasion or relationship.
    |
    */

    'weights' => [
        'occasion_match' => 25,
        'relationship_match' => 15,
        'recipient_type_match' => 15,
        'interest_match' => 10,
        'interest_match_max' => 30,
        'interest_match_extra' => 2,
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
    | price, then newer published_at, then lower product id. Match tier always
    | sorts before score (see GenerateRecommendationsAction).
    |
    */

    'tie_breakers' => [
        'score',
        'price_amount',
        'published_at',
        'id',
    ],

];
