<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_curation_decisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->uuid('source_audit_run_id');
            $table->unsignedBigInteger('source_product_curation_audit_id');
            $table->string('decision', 40);
            $table->json('reason_codes');
            $table->text('reason_notes')->nullable();
            $table->string('catalog_role', 40)->nullable();
            $table->string('remediation_status', 24);
            $table->foreignId('previous_decision_id')->nullable()->constrained('product_curation_decisions')->restrictOnDelete();
            $table->unsignedBigInteger('current_for_product_id')->nullable();
            $table->foreignId('decided_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->foreign('source_audit_run_id', 'pcd_source_run_foreign')
                ->references('id')
                ->on('product_curation_audit_runs')
                ->restrictOnDelete();
            $table->foreign('source_product_curation_audit_id', 'pcd_source_audit_foreign')
                ->references('id')
                ->on('product_curation_audits')
                ->restrictOnDelete();
            $table->unique('current_for_product_id', 'pcd_current_product_unique');
            $table->index('product_id', 'pcd_product_id_index');
            $table->index('source_audit_run_id', 'pcd_source_run_index');
            $table->index('decision', 'pcd_decision_index');
            $table->index('decided_at', 'pcd_decided_at_index');
            $table->index(['product_id', 'decided_at'], 'pcd_product_decided_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_curation_decisions');
    }
};
