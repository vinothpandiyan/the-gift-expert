<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('seo_ownership', 16)->default('source')->after('canonical_url');
            $table->unsignedSmallInteger('seo_generation_version')->nullable()->after('seo_ownership');
            $table->timestamp('seo_reviewed_at')->nullable()->after('seo_generation_version');
            $table->foreignId('seo_reviewed_by_user_id')
                ->nullable()
                ->after('seo_reviewed_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('seo_ownership');
        });

        DB::table('products')
            ->where(function ($query): void {
                $query->whereNotNull('meta_title')
                    ->orWhereNotNull('meta_description')
                    ->orWhereNotNull('canonical_url');
            })
            ->update([
                'seo_ownership' => 'human',
                'seo_reviewed_at' => DB::raw('COALESCE(updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seo_reviewed_by_user_id');
            $table->dropIndex(['seo_ownership']);
            $table->dropColumn([
                'seo_ownership',
                'seo_generation_version',
                'seo_reviewed_at',
            ]);
        });
    }
};
