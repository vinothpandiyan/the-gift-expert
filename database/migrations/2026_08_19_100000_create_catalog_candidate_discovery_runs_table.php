<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_candidate_discovery_runs', function (Blueprint $table) {
            $table->id();
            $table->string('provider_key');
            $table->text('brief');
            $table->char('market', 2);
            $table->unsignedInteger('max_candidates');
            $table->unsignedInteger('freshness_days');
            $table->enum('status', ['running', 'completed', 'completed_with_errors', 'failed']);
            $table->json('queries')->nullable();
            $table->json('retrieved_urls')->nullable();
            $table->unsignedInteger('candidates_proposed')->default(0);
            $table->unsignedBigInteger('catalog_candidate_ingestion_run_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('catalog_candidate_ingestion_run_id', 'ccdr_ingestion_run_id_foreign')
                ->references('id')
                ->on('catalog_candidate_ingestion_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_candidate_discovery_runs');
    }
};
