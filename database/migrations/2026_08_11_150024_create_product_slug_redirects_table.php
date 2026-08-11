<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_slug_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_slug')->unique();
            $table->string('to_slug');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_slug_redirects');
    }
};
