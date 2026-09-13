<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_curation_audit_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('status', ['pending', 'running', 'completed', 'completed_with_errors', 'failed']);
            $table->json('options')->nullable();
            $table->json('summary')->nullable();
            $table->unsignedInteger('products_total')->default(0);
            $table->unsignedInteger('products_processed')->default(0);
            $table->unsignedInteger('products_completed')->default(0);
            $table->unsignedInteger('products_failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();
        });

        Schema::create('product_curation_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->enum('outcome', ['pending', 'semantic_ready', 'completed', 'failed'])->default('pending');

            $table->unsignedTinyInteger('gift_score')->nullable();
            $table->unsignedTinyInteger('catalog_value_score')->nullable();
            $table->json('gift_score_components')->nullable();
            $table->json('gift_intents')->nullable();
            $table->json('strongest_fits')->nullable();
            $table->json('suggested_relationships')->nullable();
            $table->json('suggested_occasions')->nullable();
            $table->json('suggested_interests')->nullable();
            $table->json('suggested_gift_types')->nullable();
            $table->json('taxonomy_differences')->nullable();
            $table->string('concept_key', 120)->nullable();
            $table->string('concept_label', 160)->nullable();
            $table->string('catalog_role', 40)->nullable();
            $table->text('why_this_gift')->nullable();
            $table->json('strengths')->nullable();
            $table->json('issues')->nullable();
            $table->string('ai_confidence', 24)->nullable();
            $table->string('recommendation', 40)->nullable();
            $table->boolean('requires_human_review')->default(false);

            $table->json('semantic_evaluation')->nullable();
            $table->json('evidence_snapshot');
            $table->char('evidence_fingerprint', 64);
            $table->char('semantic_fingerprint', 64);
            $table->json('catalog_context_snapshot')->nullable();
            $table->char('context_fingerprint', 64)->nullable();
            $table->timestamp('context_calculated_at')->nullable();
            $table->unsignedTinyInteger('saturation_novelty_factor')->nullable();
            $table->unsignedTinyInteger('differentiation_factor')->nullable();
            $table->unsignedTinyInteger('budget_gap_factor')->nullable();
            $table->unsignedTinyInteger('taxonomy_gap_factor')->nullable();
            $table->unsignedTinyInteger('intents_factor')->nullable();
            $table->unsignedTinyInteger('niche_factor')->nullable();
            $table->json('peer_product_ids')->nullable();
            $table->json('peer_counts')->nullable();

            $table->string('ai_model')->nullable();
            $table->string('semantic_evaluator_version', 40);
            $table->string('prompt_version', 40);
            $table->string('scoring_version', 40);
            $table->string('context_version', 40);
            $table->unsignedInteger('semantic_duration_ms')->nullable();
            $table->unsignedInteger('context_duration_ms')->nullable();
            $table->text('failure')->nullable();
            $table->timestamp('semantic_completed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('product_curation_audit_runs')->restrictOnDelete();
            $table->unique(['run_id', 'product_id']);
            $table->index(['product_id', 'outcome', 'completed_at'], 'pca_product_outcome_completed_index');
            $table->index('concept_key');
            $table->index(
                ['semantic_fingerprint', 'semantic_evaluator_version', 'prompt_version'],
                'pca_semantic_freshness_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_curation_audits');
        Schema::dropIfExists('product_curation_audit_runs');
    }
};
