<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_product_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_source_list_id')->constrained('catalog_source_lists')->restrictOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedBigInteger('first_intake_run_id')->nullable();
            $table->unsignedBigInteger('last_intake_run_id')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamps();

            $table->unique(['affiliate_link_id', 'catalog_source_list_id'], 'cps_link_list_unique');
            $table->index('catalog_source_list_id', 'cps_source_list_idx');

            $table->foreign('first_intake_run_id', 'cps_first_run_foreign')
                ->references('id')
                ->on('curated_product_intake_runs')
                ->nullOnDelete();

            $table->foreign('last_intake_run_id', 'cps_last_run_foreign')
                ->references('id')
                ->on('curated_product_intake_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_sources');
    }
};
