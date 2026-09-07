<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomy_applicability_rules', function (Blueprint $table) {
            $table->id();
            $table->string('source_dimension', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('target_dimension', 32);
            $table->unsignedBigInteger('target_id');
            $table->string('effect', 16);
            $table->text('reason');
            $table->boolean('is_active')->default(true);
            $table->string('canonical_key', 80);
            $table->timestamps();

            $table->unique('canonical_key', 'tax_appl_rules_canonical_uidx');
            $table->index('is_active', 'tax_appl_rules_active_idx');
            $table->index(['source_dimension', 'source_id'], 'tax_appl_rules_source_idx');
            $table->index(['target_dimension', 'target_id'], 'tax_appl_rules_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_applicability_rules');
    }
};
