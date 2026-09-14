<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendation_sessions', function (Blueprint $table) {
            $table->foreignId('recipient_gender_id')
                ->nullable()
                ->after('recipient_type_id')
                ->constrained()
                ->restrictOnDelete();
        });

        Schema::table('seo_landing_pages', function (Blueprint $table) {
            $table->foreignId('recipient_gender_id')
                ->nullable()
                ->after('recipient_type_id')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('seo_landing_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_gender_id');
        });

        Schema::table('recommendation_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_gender_id');
        });
    }
};
