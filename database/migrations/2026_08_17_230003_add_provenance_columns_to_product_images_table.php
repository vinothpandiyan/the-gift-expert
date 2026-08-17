<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('source_url', 2000)->nullable()->after('is_primary');
            $table->char('content_hash', 64)->nullable()->after('source_url');
            $table->timestamp('acquired_at')->nullable()->after('content_hash');

            $table->index(['product_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'content_hash']);
            $table->dropColumn(['source_url', 'content_hash', 'acquired_at']);
        });
    }
};
