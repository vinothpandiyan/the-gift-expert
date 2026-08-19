<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_candidate_sourcing_items', function (Blueprint $table) {
            $table->json('enrichment')->nullable()->after('selected_offer');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_candidate_sourcing_items', function (Blueprint $table) {
            $table->dropColumn('enrichment');
        });
    }
};
