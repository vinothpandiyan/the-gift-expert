<?php

namespace Tests\Feature\CommercialSourcing;

use App\Actions\CatalogCandidate\SourceCatalogCandidatesAction;
use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Discovery\TavilyCatalogCandidateSearchProvider;
use App\Enums\CatalogCandidateStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\ConfiguresCommercialSourcing;
use Tests\Support\FakesCommercialEnrichment;
use Tests\TestCase;

class CommercialSourcingIsolationTest extends TestCase
{
    use ConfiguresCommercialSourcing;
    use FakesCommercialEnrichment;
    use RefreshDatabase;

    public function test_commercial_sourcing_does_not_write_catalog_or_import_rows(): void
    {
        Http::preventStrayRequests();
        $this->useCommercialMerchants([
            'partner-a' => $this->commercialMerchantConfig('partner-a', [
                'domains' => ['partner-a.example'],
            ]),
        ]);
        $this->createActiveMerchant('partner-a');

        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => [
                    [
                        'title' => 'BrandX French Press',
                        'url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                        'content' => '₹1,299',
                    ],
                ],
            ], 200),
        ]);

        $before = $this->catalogCounts();
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        app(SourceCatalogCandidatesAction::class)->execute(candidateId: $candidate->id);

        $this->assertSame($before, $this->catalogCounts());
        $this->assertSame(14, collect(Route::getRoutes())->filter(
            fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'),
        )->count());
    }

    public function test_enrichment_does_not_write_catalog_or_import_rows(): void
    {
        Http::preventStrayRequests();
        $this->configureCommercialEnrichment();
        $this->useCommercialMerchants([
            'partner-a' => $this->commercialMerchantConfig('partner-a', [
                'domains' => ['partner-a.example'],
                'affiliate' => [
                    'strategy' => 'query_param',
                    'param' => 'aff',
                    'value' => 'test-tag',
                ],
            ]),
        ]);
        $this->createActiveMerchant('partner-a');
        $home = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => [
                    [
                        'title' => 'BrandX French Press',
                        'url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                        'content' => '₹1,299',
                    ],
                ],
            ], 200),
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                    ],
                ]),
            ),
        ]);

        $before = $this->catalogCounts();
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        app(SourceCatalogCandidatesAction::class)->execute(candidateId: $candidate->id, enrich: true);

        $this->assertSame($before, $this->catalogCounts());
        $this->assertSame(14, collect(Route::getRoutes())->filter(
            fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'),
        )->count());
    }

    public function test_discovery_tavily_search_does_not_send_include_domains(): void
    {
        Http::preventStrayRequests();
        config([
            'catalog_candidate_discovery.search.providers.tavily.api_key' => 'tvly-discovery-key',
            'catalog_candidate_discovery.search.max_queries_per_brief' => 1,
        ]);

        Http::fake([
            'https://api.tavily.com/search' => Http::response(['results' => []], 200),
        ]);

        app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.tavily.com/search'
                && ! array_key_exists('include_domains', $request->data());
        });
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
