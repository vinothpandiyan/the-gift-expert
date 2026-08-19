<?php

namespace Tests\Feature\CommercialSourcing;

use App\Enums\CatalogCandidateStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateSourcingItem;
use App\Models\CatalogCandidateSourcingRun;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\ConfiguresCommercialSourcing;
use Tests\Support\FakesCommercialEnrichment;
use Tests\TestCase;

class SourceCatalogCandidatesCommandTest extends TestCase
{
    use ConfiguresCommercialSourcing;
    use FakesCommercialEnrichment;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->useCommercialMerchants([
            'partner-a' => $this->commercialMerchantConfig('partner-a', [
                'domains' => ['partner-a.example'],
            ]),
        ]);
        $this->createActiveMerchant('partner-a');
    }

    public function test_it_sources_an_approved_candidate_and_prints_the_offer(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => [
                    [
                        'title' => 'BrandX French Press',
                        'url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                        'content' => 'Buy BrandX French Press ₹1,299',
                    ],
                ],
            ], 200),
        ]);

        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        $this->artisan('catalog-candidates:source', [
            '--candidate' => $candidate->id,
            '--market' => 'IN',
        ])
            ->expectsOutputToContain('Sourcing run')
            ->expectsOutputToContain('merchant: partner-a')
            ->expectsOutputToContain('B0ABCDEFGH')
            ->expectsOutputToContain('1299.00')
            ->assertSuccessful();

        $this->assertSame(1, CatalogCandidateSourcingRun::query()->count());
        $this->assertSame(1, CatalogCandidateSourcingItem::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, AffiliateLink::query()->count());
        $this->assertSame(0, ProductImage::query()->count());
        $this->assertSame(0, ImportRun::query()->count());
        $this->assertSame(14, collect(Route::getRoutes())->filter(
            fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'),
        )->count());
    }

    public function test_dry_run_prints_the_offer_and_writes_nothing(): void
    {
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

        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        $this->artisan('catalog-candidates:source', [
            '--candidate' => $candidate->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run completed. No sourcing runs or catalog items were written.')
            ->expectsOutputToContain('partner-a')
            ->assertSuccessful();

        $this->assertSame(0, CatalogCandidateSourcingRun::query()->count());
        $this->assertSame(0, CatalogCandidateSourcingItem::query()->count());
        $this->assertSame(0, Product::query()->count());
    }

    public function test_default_limit_does_not_source_discovered_candidates(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response(['results' => []], 200),
        ]);

        CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Discovered,
        ]);

        $this->artisan('catalog-candidates:source')
            ->expectsOutputToContain('Total: 0')
            ->assertSuccessful();

        $this->assertSame(1, CatalogCandidateSourcingRun::query()->count());
        $this->assertSame(0, CatalogCandidateSourcingItem::query()->count());
    }

    public function test_enrich_flag_prints_readiness_fields(): void
    {
        $this->configureCommercialEnrichment();
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
                        'content' => 'BrandX French Press ₹1,299',
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

        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        $this->artisan('catalog-candidates:source', [
            '--candidate' => $candidate->id,
            '--enrich' => true,
        ])
            ->expectsOutputToContain('affiliate_ready:')
            ->expectsOutputToContain('primary_category_id:')
            ->assertSuccessful();

        $this->assertNotNull(CatalogCandidateSourcingItem::query()->first()?->enrichment);
        $this->assertSame(0, Product::query()->count());
    }
}
