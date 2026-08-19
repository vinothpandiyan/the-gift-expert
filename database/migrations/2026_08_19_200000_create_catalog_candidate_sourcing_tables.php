<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_candidate_sourcing_runs', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['running', 'completed', 'completed_with_errors', 'failed']);
            $table->char('market', 2);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_succeeded')->default(0);
            $table->unsignedInteger('items_skipped')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('catalog_candidate_sourcing_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('catalog_candidate_sourcing_run_id');
            $table->foreignId('catalog_candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->json('selected_offer')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('affiliate_link_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['succeeded', 'skipped', 'failed']);
            $table->string('readiness', 32)->nullable();
            $table->json('exception_codes')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('catalog_candidate_sourcing_run_id', 'ccsi_run_id_foreign')
                ->references('id')
                ->on('catalog_candidate_sourcing_runs')
                ->cascadeOnDelete();

            $table->unique(['catalog_candidate_sourcing_run_id', 'catalog_candidate_id'], 'ccsi_run_candidate_unique');
            $table->index(['catalog_candidate_sourcing_run_id', 'status'], 'ccsi_run_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_candidate_sourcing_items');
        Schema::dropIfExists('catalog_candidate_sourcing_runs');
    }
};
