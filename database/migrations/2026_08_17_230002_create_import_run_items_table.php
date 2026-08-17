<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained()->cascadeOnDelete();
            $table->string('external_product_id', 120);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('affiliate_link_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['pending', 'succeeded', 'failed', 'skipped'])->default('pending');
            $table->text('error')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamps();

            $table->unique(['import_run_id', 'external_product_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_run_items');
    }
};
