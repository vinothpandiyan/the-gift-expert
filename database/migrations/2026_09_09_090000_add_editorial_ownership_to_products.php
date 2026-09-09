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
            $table->string('editorial_ownership', 16)->default('source')->after('description');
            $table->unsignedSmallInteger('editorial_generation_version')->nullable()->after('editorial_ownership');
            $table->timestamp('editorial_reviewed_at')->nullable()->after('editorial_generation_version');
            $table->foreignId('editorial_reviewed_by_user_id')
                ->nullable()
                ->after('editorial_reviewed_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('editorial_ownership');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::table('products')
                ->whereNotNull('taxonomy_classification_proposal')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(taxonomy_classification_proposal, '$.name')) = name")
                ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(taxonomy_classification_proposal, '$.short_description')), '') = COALESCE(short_description, '')")
                ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(taxonomy_classification_proposal, '$.description')), '') = COALESCE(description, '')")
                ->update([
                    'editorial_ownership' => 'ai',
                    'editorial_generation_version' => 0,
                ]);
        }

        DB::table('products')
            ->where(function ($query): void {
                $query->where('status', 'published')
                    ->orWhereNotNull('published_at')
                    ->orWhereNotExists(function ($items): void {
                        $items->selectRaw('1')
                            ->from('curated_product_intake_items')
                            ->whereColumn('curated_product_intake_items.product_id', 'products.id');
                    });
            })
            ->update([
                'editorial_ownership' => 'human',
                'editorial_generation_version' => null,
                'editorial_reviewed_at' => DB::raw('COALESCE(published_at, updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('editorial_reviewed_by_user_id');
            $table->dropIndex(['editorial_ownership']);
            $table->dropColumn([
                'editorial_ownership',
                'editorial_generation_version',
                'editorial_reviewed_at',
            ]);
        });
    }
};
