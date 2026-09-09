<?php

use App\Actions\Product\BackfillLegacyPublishedProductTaxonomyClassificationAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('taxonomy_proposal_pending')->default(false)->after('taxonomy_classification_proposal');
        });

        app(BackfillLegacyPublishedProductTaxonomyClassificationAction::class)->execute();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('taxonomy_proposal_pending');
        });
    }
};
