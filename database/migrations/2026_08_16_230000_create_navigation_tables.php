<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_menus', function (Blueprint $table) {
            $table->id();
            $table->string('label', 120);
            $table->string('slug')->unique();
            $table->enum('item_type', ['mega', 'link'])->default('mega');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->enum('link_type', [
                'relationship',
                'occasion',
                'interest',
                'profession',
                'recipient_type',
                'gift_type',
                'category',
                'seo_landing_page',
                'discovery_route',
                'external_url',
            ])->nullable();
            $table->unsignedBigInteger('linkable_id')->nullable();
            $table->string('route_key')->nullable();
            $table->string('url', 500)->nullable();
            $table->boolean('opens_in_new_tab')->default(false);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('navigation_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('navigation_menu_id')->constrained()->cascadeOnDelete();
            $table->string('heading', 120)->nullable();
            $table->enum('appearance', ['default', 'cta'])->default('default');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('navigation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('navigation_section_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->enum('link_type', [
                'relationship',
                'occasion',
                'interest',
                'profession',
                'recipient_type',
                'gift_type',
                'category',
                'seo_landing_page',
                'discovery_route',
                'external_url',
            ]);
            $table->unsignedBigInteger('linkable_id')->nullable();
            $table->string('route_key')->nullable();
            $table->string('url', 500)->nullable();
            $table->boolean('opens_in_new_tab')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_links');
        Schema::dropIfExists('navigation_sections');
        Schema::dropIfExists('navigation_menus');
    }
};
