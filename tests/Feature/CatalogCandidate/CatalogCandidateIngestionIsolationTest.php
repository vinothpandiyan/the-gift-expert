<?php

namespace Tests\Feature\CatalogCandidate;

use App\Actions\CatalogCandidate\IngestCatalogCandidatesAction;
use App\CatalogCandidate\Ingestion\JsonCatalogCandidateParser;
use App\Enums\CatalogCandidateIngestionFormat;
use App\Models\AffiliateLink;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CatalogCandidateIngestionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingestion_does_not_touch_the_product_catalog_or_phase_17(): void
    {
        $before = [
            'products' => Product::query()->withTrashed()->count(),
            'affiliate_links' => AffiliateLink::query()->withTrashed()->count(),
            'product_images' => ProductImage::query()->count(),
            'import_runs' => ImportRun::query()->count(),
            'category_product' => DB::table('category_product')->count(),
            'occasion_product' => DB::table('occasion_product')->count(),
            'relationship_product' => DB::table('relationship_product')->count(),
            'recipient_type_product' => DB::table('recipient_type_product')->count(),
            'interest_product' => DB::table('interest_product')->count(),
            'profession_product' => DB::table('profession_product')->count(),
            'gift_type_product' => DB::table('gift_type_product')->count(),
        ];

        app(IngestCatalogCandidatesAction::class)->execute(
            app(JsonCatalogCandidateParser::class)->parse(
                file_get_contents(base_path('tests/Fixtures/catalog-candidates/valid.json')),
            ),
            CatalogCandidateIngestionFormat::Json,
            'valid.json',
        );

        $this->assertSame($before, [
            'products' => Product::query()->withTrashed()->count(),
            'affiliate_links' => AffiliateLink::query()->withTrashed()->count(),
            'product_images' => ProductImage::query()->count(),
            'import_runs' => ImportRun::query()->count(),
            'category_product' => DB::table('category_product')->count(),
            'occasion_product' => DB::table('occasion_product')->count(),
            'relationship_product' => DB::table('relationship_product')->count(),
            'recipient_type_product' => DB::table('recipient_type_product')->count(),
            'interest_product' => DB::table('interest_product')->count(),
            'profession_product' => DB::table('profession_product')->count(),
            'gift_type_product' => DB::table('gift_type_product')->count(),
        ]);
        $this->assertSame(14, collect(Route::getRoutes())->filter(
            fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'),
        )->count());
    }
}
