<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('url', 2000);
            $table->string('external_product_id', 120)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'status']);
            $table->index(['product_id', 'is_primary']);
            $table->index(['merchant_id', 'external_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_links');
    }
};
