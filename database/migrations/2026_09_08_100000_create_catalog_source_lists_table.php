<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_source_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('external_list_id', 120)->nullable();
            $table->string('name');
            $table->string('normalized_name', 191);
            $table->string('source_url', 2000)->nullable();
            $table->string('kind', 32);
            $table->foreignId('relationship_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('identity_key', 191)->storedAs(
                "if(`external_list_id` is not null, concat('id:', `external_list_id`), concat('name:', `normalized_name`))"
            );
            $table->timestamps();

            $table->unique(['merchant_id', 'identity_key'], 'csl_merchant_identity_unique');
            $table->index(['merchant_id', 'external_list_id'], 'csl_merchant_external_list_idx');
            $table->index(['merchant_id', 'normalized_name'], 'csl_merchant_normalized_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_source_lists');
    }
};
