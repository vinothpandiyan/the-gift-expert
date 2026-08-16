<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_landing_pages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug')->unique();
            $table->string('heading');
            $table->text('intro_content')->nullable();
            $table->longText('body_content')->nullable();
            $table->longText('faq_content')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->boolean('is_indexable')->default(false);
            $table->boolean('include_in_sitemap')->default(false);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('canonical_url', 500)->nullable();
            $table->foreignId('occasion_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('relationship_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('recipient_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('profession_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('gift_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('budget_range_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index('is_indexable');
            $table->index('include_in_sitemap');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_landing_pages');
    }
};
