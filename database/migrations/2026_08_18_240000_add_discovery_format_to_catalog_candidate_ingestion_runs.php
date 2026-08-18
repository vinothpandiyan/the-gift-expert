<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE catalog_candidate_ingestion_runs MODIFY format ENUM('csv', 'json', 'discovery') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE catalog_candidate_ingestion_runs MODIFY format ENUM('csv', 'json') NOT NULL");
    }
};
