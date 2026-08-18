<?php

namespace Tests\Feature\CatalogCandidate;

use App\Actions\CatalogCandidate\DiscoverCatalogCandidatesAction;
use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateDiscoveryRun;
use App\Models\CatalogCandidateEvidence;
use App\Models\CatalogCandidateIngestionItem;
use App\Models\CatalogCandidateIngestionRun;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CatalogCandidateDiscoveryIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_discovery_does_not_touch_the_product_catalog_or_phase_17(): void
    {
        $before = $this->catalogCounts();

        app(DiscoverCatalogCandidatesAction::class)->execute(
            CatalogCandidateResearchBrief::from('Find useful birthday gift ideas for husbands in India'),
        );

        $this->assertSame($before, $this->catalogCounts());
        $this->assertSame(14, collect(Route::getRoutes())->filter(
            fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'),
        )->count());
    }

    public function test_search_only_does_not_write_candidates_or_the_product_catalog(): void
    {
        config([
            'catalog_candidate_discovery.search.providers.tavily.api_key' => 'tvly-test-key',
            'catalog_candidate_discovery.search.max_queries_per_brief' => 1,
        ]);

        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'query' => 'thoughtful gifts',
                'results' => [
                    [
                        'title' => 'Roundup',
                        'url' => 'https://example.com/gifts',
                        'content' => 'Useful gifts',
                    ],
                ],
            ], 200),
        ]);

        $before = $this->catalogCounts();

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'thoughtful gifts',
            '--search-only' => true,
        ])->assertSuccessful();

        $this->assertSame($before, $this->catalogCounts());
        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateEvidence::query()->count());
        $this->assertSame(0, CatalogCandidateDiscoveryRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionItem::query()->count());
        $this->assertSame(14, collect(Route::getRoutes())->filter(
            fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'),
        )->count());
    }

    /**
     * @return array<string, int>
     */
    private function catalogCounts(): array
    {
        return [
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
    }
}
