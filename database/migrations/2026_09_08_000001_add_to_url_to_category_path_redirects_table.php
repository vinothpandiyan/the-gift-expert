<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('category_path_redirects', function (Blueprint $table) {
            $table->string('to_url', 2048)->nullable()->after('to_path');
        });

        DB::statement('ALTER TABLE category_path_redirects MODIFY to_path VARCHAR(1000) NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE category_path_redirects SET to_path = '' WHERE to_path IS NULL");
        DB::statement('ALTER TABLE category_path_redirects MODIFY to_path VARCHAR(1000) NOT NULL');

        Schema::table('category_path_redirects', function (Blueprint $table) {
            $table->dropColumn('to_url');
        });
    }
};
