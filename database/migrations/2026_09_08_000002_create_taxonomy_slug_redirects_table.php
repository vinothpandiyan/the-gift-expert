<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomy_slug_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('taxonomy', 50);
            $table->string('from_slug', 120);
            $table->string('to_slug', 120);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['taxonomy', 'from_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_slug_redirects');
    }
};
