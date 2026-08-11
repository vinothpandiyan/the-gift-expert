<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('occasion_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('budget_range_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('relationship_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('recipient_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('profession_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('gift_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('referrer_url', 500)->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('occasion_id');
            $table->index('budget_range_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_sessions');
    }
};
