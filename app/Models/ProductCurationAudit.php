<?php

namespace App\Models;

use App\Enums\CatalogRole;
use App\Enums\CurationAiConfidence;
use App\Enums\CurationRecommendation;
use App\Enums\ProductCurationAuditOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCurationAudit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'outcome' => ProductCurationAuditOutcome::class,
            'gift_score' => 'integer',
            'catalog_value_score' => 'integer',
            'gift_score_components' => 'array',
            'gift_intents' => 'array',
            'strongest_fits' => 'array',
            'suggested_relationships' => 'array',
            'suggested_occasions' => 'array',
            'suggested_interests' => 'array',
            'suggested_gift_types' => 'array',
            'taxonomy_differences' => 'array',
            'strengths' => 'array',
            'catalog_role' => CatalogRole::class,
            'issues' => 'array',
            'ai_confidence' => CurationAiConfidence::class,
            'recommendation' => CurationRecommendation::class,
            'requires_human_review' => 'boolean',
            'semantic_evaluation' => 'array',
            'evidence_snapshot' => 'array',
            'catalog_context_snapshot' => 'array',
            'peer_product_ids' => 'array',
            'peer_counts' => 'array',
            'saturation_novelty_factor' => 'integer',
            'differentiation_factor' => 'integer',
            'budget_gap_factor' => 'integer',
            'taxonomy_gap_factor' => 'integer',
            'intents_factor' => 'integer',
            'niche_factor' => 'integer',
            'semantic_duration_ms' => 'integer',
            'context_duration_ms' => 'integer',
            'semantic_completed_at' => 'datetime',
            'context_calculated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductCurationAuditRun::class, 'run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
