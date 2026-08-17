<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('affiliate_links')
            ->select('merchant_id', 'external_product_id', DB::raw('COUNT(*) as aggregate'))
            ->whereNotNull('external_product_id')
            ->groupBy('merchant_id', 'external_product_id')
            ->having('aggregate', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $summary = $duplicates
                ->map(fn ($row): string => "merchant_id={$row->merchant_id} external_product_id={$row->external_product_id} count={$row->aggregate}")
                ->implode('; ');

            throw new RuntimeException(
                'Cannot add unique affiliate identity: duplicate non-null (merchant_id, external_product_id) rows exist. '.$summary
            );
        }

        Schema::table('affiliate_links', function (Blueprint $table) {
            $table->unique(['merchant_id', 'external_product_id']);
        });

        Schema::table('affiliate_links', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'external_product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_links', function (Blueprint $table) {
            $table->index(['merchant_id', 'external_product_id']);
        });

        Schema::table('affiliate_links', function (Blueprint $table) {
            $table->dropUnique(['merchant_id', 'external_product_id']);
        });
    }
};
