<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_path_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 1000);
            $table->string('to_path', 1000);
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement('CREATE UNIQUE INDEX category_path_redirects_from_path_unique ON category_path_redirects (from_path(768))');
    }

    public function down(): void
    {
        Schema::dropIfExists('category_path_redirects');
    }
};
