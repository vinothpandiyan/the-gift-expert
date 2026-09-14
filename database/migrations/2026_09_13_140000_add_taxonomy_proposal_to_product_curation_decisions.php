<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_curation_decisions', function (Blueprint $table) {
            $table->json('taxonomy_proposal')->nullable()->after('catalog_role');
            $table->json('remediation_manifest')->nullable()->after('remediation_status');
            $table->timestamp('remediated_at')->nullable()->after('decided_at');
            $table->foreignId('remediated_by_user_id')->nullable()->after('remediated_at')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_curation_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('remediated_by_user_id');
            $table->dropColumn([
                'taxonomy_proposal',
                'remediation_manifest',
                'remediated_at',
            ]);
        });
    }
};
