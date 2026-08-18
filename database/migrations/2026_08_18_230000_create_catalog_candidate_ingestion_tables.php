<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_candidate_ingestion_runs', function (Blueprint $table) {
            $table->id();
            $table->enum('format', ['csv', 'json']);
            $table->string('source_name', 255);
            $table->enum('status', ['completed', 'completed_with_errors', 'failed']);
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

        Schema::create('catalog_candidate_ingestion_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('catalog_candidate_ingestion_run_id');
            $table->unsignedInteger('item_index');
            $table->string('title')->nullable();
            $table->foreignId('catalog_candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['succeeded', 'skipped', 'failed']);
            $table->text('error')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamps();

            $table->foreign('catalog_candidate_ingestion_run_id', 'ccii_run_id_foreign')
                ->references('id')
                ->on('catalog_candidate_ingestion_runs')
                ->cascadeOnDelete();

            $table->index(['catalog_candidate_ingestion_run_id', 'status'], 'ccii_run_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_candidate_ingestion_items');
        Schema::dropIfExists('catalog_candidate_ingestion_runs');
    }
};
