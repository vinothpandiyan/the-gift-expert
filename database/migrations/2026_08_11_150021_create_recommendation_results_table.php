<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('score', 8, 2);
            $table->unsignedSmallInteger('rank');
            $table->json('score_breakdown');
            $table->string('explanation', 1000);
            $table->timestamps();

            $table->unique(['recommendation_session_id', 'product_id'], 'rec_results_session_product_unique');
            $table->index(['recommendation_session_id', 'rank'], 'rec_results_session_rank_index');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_results');
    }
};
