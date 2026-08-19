<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->text('brief');
            $table->char('market', 2);
            $table->unsignedInteger('max_candidates');
            $table->unsignedInteger('freshness_days');
            $table->enum('status', ['running', 'completed', 'completed_with_errors', 'failed']);
            $table->enum('current_stage', ['discovery', 'sourcing', 'enrichment', 'promotion', 'readiness']);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('discovery_run_id')->nullable();
            $table->unsignedBigInteger('sourcing_run_id')->nullable();
            $table->json('counts')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('discovery_run_id', 'car_discovery_run_fk')
                ->references('id')
                ->on('catalog_candidate_discovery_runs')
                ->nullOnDelete();

            $table->foreign('sourcing_run_id', 'car_sourcing_run_fk')
                ->references('id')
                ->on('catalog_candidate_sourcing_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_automation_runs');
    }
};
