<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_gender_product', function (Blueprint $table) {
            $table->foreignId('recipient_gender_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['recipient_gender_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_gender_product');
    }
};
