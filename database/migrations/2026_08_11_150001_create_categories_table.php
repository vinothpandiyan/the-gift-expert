<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug');
            $table->string('full_path', 1000);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['parent_id', 'slug']);
            $table->index(['parent_id', 'sort_order']);
            $table->index('is_active');
        });

        DB::statement('CREATE UNIQUE INDEX categories_full_path_unique ON categories (full_path(768))');
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
