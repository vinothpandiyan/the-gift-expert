<?php

return [

    'default_market' => 'IN',

    /*
    |--------------------------------------------------------------------------
    | Candidate gate
    |--------------------------------------------------------------------------
    |
    | approved_only — only approved candidates are sourced (standalone default).
    | discovered_and_approved — orchestrator may source discovered candidates.
    |
    */

    'candidate_gate' => 'discovered_and_approved',

    'continue_existing_candidates' => true,

    're_source_existing' => false,

    'max_candidates_per_run' => 20,

    'max_products_promoted_per_run' => 20,

    /*
    |--------------------------------------------------------------------------
    | Publication
    |--------------------------------------------------------------------------
    |
    | Orchestration never auto-publishes. Human bulk publish remains required.
    |
    */

    'auto_publish' => false,

];
