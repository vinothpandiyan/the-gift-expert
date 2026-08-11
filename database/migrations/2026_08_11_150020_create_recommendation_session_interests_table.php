<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_session_interests', function (Blueprint $table) {
            $table->unsignedBigInteger('recommendation_session_id');
            $table->unsignedBigInteger('interest_id');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('recommendation_session_id', 'rsi_session_id_foreign')
                ->references('id')
                ->on('recommendation_sessions')
                ->cascadeOnDelete();

            $table->foreign('interest_id', 'rsi_interest_id_foreign')
                ->references('id')
                ->on('interests')
                ->restrictOnDelete();

            $table->unique(['recommendation_session_id', 'interest_id'], 'rsi_session_interest_unique');
            $table->index('interest_id', 'rsi_interest_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_session_interests');
    }
};
