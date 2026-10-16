<?php

/*
|--------------------------------------------------------------------------
| Recipient Eligibility
|--------------------------------------------------------------------------
|
| Compute-on-read recipient eligibility for discovery surfaces and the Gift
| Finder. Everything here is disabled by default; with the master switch off
| every query is identical to the strict, explicit-pivot behavior.
|
| Nothing in this feature writes Relationship pivots or changes product
| classification. SEO publication, sitemap and landing-page checks never read
| these flags (they call the query action in strict mode by contract).
|
*/

return [

    /*
    | Master kill switch. Off => every surface, the Finder, pool ordering and
    | contradiction handling behave exactly as before.
    */
    'enabled' => (bool) env('RECIPIENT_ELIGIBILITY_ENABLED', false),

    /*
    | Shadow mode: sampled runtime logging of strict vs eligible result counts.
    | Never changes live results.
    */
    'shadow' => (bool) env('RECIPIENT_ELIGIBILITY_SHADOW', false),
    'shadow_sample_rate' => (float) env('RECIPIENT_ELIGIBILITY_SHADOW_SAMPLE_RATE', 0.1),

    /*
    | Per-surface mode: off | inferred. A surface only infers for relationships
    | whose profile below has `infer` enabled. `seo_landing` is deliberately not
    | configurable and is always strict.
    */
    'surfaces' => [
        'relationship' => env('RECIPIENT_ELIGIBILITY_SURFACE_RELATIONSHIP', 'off'),
        'occasion' => env('RECIPIENT_ELIGIBILITY_SURFACE_OCCASION', 'off'),
        'category' => env('RECIPIENT_ELIGIBILITY_SURFACE_CATEGORY', 'off'),
        'interest' => env('RECIPIENT_ELIGIBILITY_SURFACE_INTEREST', 'off'),
        'gift_type' => env('RECIPIENT_ELIGIBILITY_SURFACE_GIFT_TYPE', 'off'),
        'profession' => env('RECIPIENT_ELIGIBILITY_SURFACE_PROFESSION', 'off'),
        'gift_ideas' => env('RECIPIENT_ELIGIBILITY_SURFACE_GIFT_IDEAS', 'off'),
        'recipient_type' => 'off',
        'finder' => env('RECIPIENT_ELIGIBILITY_FINDER', 'off'),
    ],

    /*
    | Explicit-tag contradictions (known gender mismatch, EXCLUDE gift type pairs,
    | no applicable occasion): off | shadow | enforce. Missing gender is never a
    | mismatch. Enforce only after the live conflicts have been curated.
    */
    'explicit_contradictions' => env('RECIPIENT_ELIGIBILITY_EXPLICIT_CONTRADICTIONS', 'off'),

    /*
    | Finder: apply contradiction filtering to every retrieval stage (including
    | the interest-first stage) and demote exclusively child-oriented products
    | inside their relevance group. Independent of the stage 2/3 inference flag.
    */
    'finder_audience' => (bool) env('RECIPIENT_ELIGIBILITY_FINDER_AUDIENCE', false),

    /*
    | Relevance-first ordering of the bounded candidate pool (only matters when
    | the eligible set exceeds discovery_ranking.candidate_pool_max).
    */
    'pool_ordering' => (bool) env('RECIPIENT_ELIGIBILITY_POOL_ORDERING', false),

    /*
    | Provisional ranking weight for an inferred relationship match (explicit
    | matches keep discovery_ranking.weights.relationship_match).
    */
    'inferred_relationship_weight' => (int) env('RECIPIENT_ELIGIBILITY_INFERRED_WEIGHT', 6),

    /*
    | Relationship profiles. Every active relationship must have a profile
    | (fail-closed; see the config integrity test).
    |
    | wave 1: Husband, Wife, Boyfriend, Girlfriend. Their `infer` flags are
    | independent. wave 2 (Brother, Sister, Friends) is deferred: its flags are
    | not wired to the environment and the code ignores `infer` outside wave 1.
    */
    'relationships' => [
        'husband' => ['wave' => 1, 'gender' => 'male', 'infer' => (bool) env('RECIPIENT_ELIGIBILITY_INFER_HUSBAND', false)],
        'boyfriend' => ['wave' => 1, 'gender' => 'male', 'infer' => (bool) env('RECIPIENT_ELIGIBILITY_INFER_BOYFRIEND', false)],
        'wife' => ['wave' => 1, 'gender' => 'female', 'infer' => (bool) env('RECIPIENT_ELIGIBILITY_INFER_WIFE', false)],
        'girlfriend' => ['wave' => 1, 'gender' => 'female', 'infer' => (bool) env('RECIPIENT_ELIGIBILITY_INFER_GIRLFRIEND', false)],

        'brother' => ['wave' => 2, 'gender' => 'male', 'infer' => false],
        'sister' => ['wave' => 2, 'gender' => 'female', 'infer' => false],
        'friends' => ['wave' => 2, 'gender' => null, 'infer' => false],

        'father' => ['wave' => null, 'gender' => 'male', 'infer' => false],
        'son' => ['wave' => null, 'gender' => 'male', 'infer' => false],
        'mother' => ['wave' => null, 'gender' => 'female', 'infer' => false],
        'daughter' => ['wave' => null, 'gender' => 'female', 'infer' => false],
        'parents' => ['wave' => null, 'gender' => null, 'infer' => false],
        'colleagues' => ['wave' => null, 'gender' => null, 'infer' => false],
        'boss' => ['wave' => null, 'gender' => null, 'infer' => false],
        'newlyweds' => ['wave' => null, 'gender' => null, 'infer' => false],
        'grandparents' => ['wave' => null, 'gender' => null, 'infer' => false],
    ],

    /*
    | Audience guards (slugs).
    |
    | A product is exclusively child-oriented when it has recipient types and all
    | of them are in `child_audience_types`. Suitable-for-children products that
    | also span an adult life stage (college-student, senior, couple) are not
    | exclusive. Relationships in `child_oriented_relationships` are exempt.
    */
    'child_audience_types' => ['baby', 'kids', 'teen', 'school-student', 'pet'],
    'child_oriented_relationships' => ['son', 'daughter'],

    /*
    | Categories (full_path, including descendants) where inference is blocked
    | unless the product is explicitly tagged: audience-restricted merchandise.
    */
    'inference_blocked_category_paths' => ['toys-and-games'],

    /*
    | Review gate: a relationship profile only infers when its reviewed sample
    | meets `audit.min_suitability` (recorded by `recipient-eligibility:review-gate`
    | in the file below). Enabled by default so a flag alone cannot activate
    | inference in production; set RECIPIENT_ELIGIBILITY_REQUIRE_REVIEW_GATE=false
    | only for local experiments.
    */
    'review_gate' => [
        'required' => (bool) env('RECIPIENT_ELIGIBILITY_REQUIRE_REVIEW_GATE', true),
        'file' => storage_path('app/audit/activation-gate.json'),
    ],

    /*
    | Shadow-audit and baseline settings.
    */
    'audit' => [
        'min_reviewed' => 30,
        'top_n' => 12,
        'sample_size' => 50,
        'min_suitability' => 0.95,
        'golden_session_ids' => [16, 17, 18, 19, 20, 21, 22, 23, 24, 25],
        'pool_sizes' => [40, 80, 500, 0],
    ],

];
