<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('affiliate_link_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('recommendation_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('recommendation_result_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('referrer_url', 500)->nullable();
            $table->string('landing_path')->nullable();
            $table->timestamp('clicked_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['affiliate_link_id', 'clicked_at']);
            $table->index(['product_id', 'clicked_at']);
            $table->index('recommendation_session_id');
            $table->index('clicked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_clicks');
    }
};
