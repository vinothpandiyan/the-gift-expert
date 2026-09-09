<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curated_product_intake_runs', function (Blueprint $table) {
            $table->unsignedInteger('raw_occurrences')->default(0)->after('items_failed');
            $table->unsignedInteger('unique_products')->default(0)->after('raw_occurrences');
            $table->unsignedInteger('merged_occurrences')->default(0)->after('unique_products');
        });

        Schema::table('curated_product_intake_items', function (Blueprint $table) {
            $table->json('source_list_ids')->nullable()->after('error');
            $table->unsignedInteger('occurrences_merged')->default(1)->after('source_list_ids');
        });
    }

    public function down(): void
    {
        Schema::table('curated_product_intake_items', function (Blueprint $table) {
            $table->dropColumn(['source_list_ids', 'occurrences_merged']);
        });

        Schema::table('curated_product_intake_runs', function (Blueprint $table) {
            $table->dropColumn(['raw_occurrences', 'unique_products', 'merged_occurrences']);
        });
    }
};
