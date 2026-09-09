<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('taxonomy_classification_status', 32)->default('none')->after('published_at');
            $table->timestamp('taxonomy_classified_at')->nullable()->after('taxonomy_classification_status');
            $table->string('taxonomy_content_fingerprint', 64)->nullable()->after('taxonomy_classified_at');
            $table->string('taxonomy_relationship_hint_fingerprint', 64)->nullable()->after('taxonomy_content_fingerprint');
            $table->unsignedSmallInteger('taxonomy_classification_version')->nullable()->after('taxonomy_relationship_hint_fingerprint');
            $table->json('taxonomy_review_reasons')->nullable()->after('taxonomy_classification_version');
            $table->json('taxonomy_classification_warnings')->nullable()->after('taxonomy_review_reasons');
            $table->string('taxonomy_gap_suggestion')->nullable()->after('taxonomy_classification_warnings');
            $table->text('taxonomy_gap_explanation')->nullable()->after('taxonomy_gap_suggestion');
            $table->json('taxonomy_reasoning')->nullable()->after('taxonomy_gap_explanation');
            $table->json('taxonomy_classification_proposal')->nullable()->after('taxonomy_reasoning');
            $table->timestamp('taxonomy_approved_at')->nullable()->after('taxonomy_classification_proposal');
            $table->foreignId('taxonomy_approved_by_user_id')
                ->nullable()
                ->after('taxonomy_approved_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('taxonomy_classification_status');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('taxonomy_approved_by_user_id');
            $table->dropIndex(['taxonomy_classification_status']);
            $table->dropColumn([
                'taxonomy_classification_status',
                'taxonomy_classified_at',
                'taxonomy_content_fingerprint',
                'taxonomy_relationship_hint_fingerprint',
                'taxonomy_classification_version',
                'taxonomy_review_reasons',
                'taxonomy_classification_warnings',
                'taxonomy_gap_suggestion',
                'taxonomy_gap_explanation',
                'taxonomy_reasoning',
                'taxonomy_classification_proposal',
                'taxonomy_approved_at',
            ]);
        });
    }
};
