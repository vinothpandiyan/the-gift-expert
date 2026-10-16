<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Result Limits
    |--------------------------------------------------------------------------
    |
    | The Gift Finder ranks gifts rather than filtering them away. Candidates
    | are retrieved in stages (see GenerateRecommendationsAction) until at least
    | `top_n` are available, then ranked and truncated. Selected interests are
    | always retrieved, regardless of `top_n`.
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
    | Scores order gifts *within* a relevance group. When interests are selected
    | the group (not the score) decides whether an interest match outranks a
    | generic relationship + occasion match. Each matched interest adds
    | `interest_match` until `interest_match_max` is reached; matches beyond that
    | add only `interest_match_extra` each, so a 4th/5th selected interest
    | differentiates gifts slightly.
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
    | Applied in order within the same relevance group: higher score first, then
    | more matched selected interests, then featured gifts, then lower price,
    | then newer published_at, then lower product id. Price is deliberately a
    | late tie-break. Relevance group always sorts before score (see
    | GenerateRecommendationsAction and RecommendationResult::GROUP_*).
    |
    */

    'tie_breakers' => [
        'score',
        'interest_overlap',
        'featured',
        'price_amount',
        'published_at',
        'id',
    ],

];
