<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curated_product_intake_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 32)->default('browser_json');
            $table->enum('status', ['processing', 'completed', 'completed_with_errors', 'failed']);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedInteger('items_updated')->default(0);
            $table->unsignedInteger('items_skipped')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('curated_product_intake_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('curated_product_intake_run_id');
            $table->unsignedInteger('item_index');
            $table->string('external_product_id', 64);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('affiliate_link_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('outcome', ['created', 'updated', 'skipped', 'failed']);
            $table->json('source_payload')->nullable();
            $table->json('warnings')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('curated_product_intake_run_id', 'cpii_run_id_foreign')
                ->references('id')
                ->on('curated_product_intake_runs')
                ->cascadeOnDelete();

            $table->index(['curated_product_intake_run_id', 'outcome'], 'cpii_run_outcome_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curated_product_intake_items');
        Schema::dropIfExists('curated_product_intake_runs');
    }
};
