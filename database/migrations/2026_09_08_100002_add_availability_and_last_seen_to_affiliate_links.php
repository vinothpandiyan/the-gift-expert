<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_links', function (Blueprint $table) {
            $table->string('availability', 32)->nullable()->after('last_verified_at');
            $table->timestamp('last_seen_at')->nullable()->after('availability');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_links', function (Blueprint $table) {
            $table->dropColumn(['availability', 'last_seen_at']);
        });
    }
};
