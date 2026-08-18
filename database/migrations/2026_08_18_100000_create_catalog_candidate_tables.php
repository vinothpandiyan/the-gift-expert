<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->char('title_fingerprint', 64);
            $table->text('summary')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['discovered', 'under_review', 'approved', 'rejected'])->default('discovered');
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->enum('source_type', [
                'manual',
                'editorial',
                'web',
                'community',
                'trend',
                'merchant',
                'affiliate',
                'ai_research',
            ]);
            $table->string('source_name', 120)->nullable();
            $table->string('source_url', 2000)->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->decimal('estimated_price_amount', 10, 2)->nullable();
            $table->char('estimated_price_currency', 3)->nullable();
            $table->timestamp('discovered_at');
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('priority');
            $table->index('source_type');
            $table->index('title_fingerprint');
            $table->index('discovered_at');
            $table->index(['source_type', 'external_reference']);
        });

        DB::statement('CREATE INDEX catalog_candidates_source_url_index ON catalog_candidates (source_url(768))');

        Schema::create('catalog_candidate_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_candidate_id')->constrained()->cascadeOnDelete();
            $table->enum('source_type', [
                'manual',
                'editorial',
                'web',
                'community',
                'trend',
                'merchant',
                'affiliate',
                'ai_research',
            ]);
            $table->string('source_name', 120)->nullable();
            $table->string('source_url', 2000)->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('observed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['catalog_candidate_id', 'observed_at'], 'cce_candidate_observed_at_index');
        });

        DB::statement('CREATE UNIQUE INDEX cce_candidate_source_url_unique ON catalog_candidate_evidence (catalog_candidate_id, source_url(760))');
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_candidate_evidence');
        Schema::dropIfExists('catalog_candidates');
    }
};
