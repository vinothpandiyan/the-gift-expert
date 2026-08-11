<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('brand', 120)->nullable();
            $table->string('sku', 80)->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->decimal('price_amount', 10, 2)->nullable();
            $table->char('price_currency', 3)->default('INR');
            $table->decimal('compare_at_amount', 10, 2)->nullable();
            $table->boolean('is_featured')->default(false);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('canonical_url', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['status', 'is_featured']);
            $table->index(['price_amount', 'price_currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
