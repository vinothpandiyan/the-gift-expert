<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_landing_page_interests', function (Blueprint $table) {
            $table->unsignedBigInteger('seo_landing_page_id');
            $table->unsignedBigInteger('interest_id');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('seo_landing_page_id', 'slpi_page_id_foreign')
                ->references('id')
                ->on('seo_landing_pages')
                ->cascadeOnDelete();

            $table->foreign('interest_id', 'slpi_interest_id_foreign')
                ->references('id')
                ->on('interests')
                ->restrictOnDelete();

            $table->unique(['seo_landing_page_id', 'interest_id'], 'slpi_page_interest_unique');
            $table->index('interest_id', 'slpi_interest_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_landing_page_interests');
    }
};
