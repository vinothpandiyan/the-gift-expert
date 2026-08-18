<?php

namespace Tests\Feature\CatalogCandidate;

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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscoverCatalogCandidatesSearchOnlyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'catalog_candidate_discovery.search.providers.tavily.api_key' => 'tvly-test-key',
            'catalog_candidate_discovery.search.max_queries_per_brief' => 2,
        ]);
    }

    public function test_search_only_prints_queries_and_sources_without_writes(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'query' => 'thoughtful gifts',
                'results' => [
                    [
                        'title' => 'Office gift roundup',
                        'url' => 'https://www.example.com/gifts',
                        'content' => 'Desk accessories and useful presents.',
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('catalog-candidates:discover', [
            'brief' => 'thoughtful gifts',
            '--market' => 'IN',
            '--search-only' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SEARCH-ONLY', $output);
        $this->assertStringContainsString('thoughtful gifts India', $output);
        $this->assertStringContainsString('Office gift roundup', $output);
        $this->assertStringContainsString('example.com', $output);
        $this->assertStringContainsString('https://www.example.com/gifts', $output);
        $this->assertStringContainsString('Desk accessories', $output);

        $this->assertNoCatalogWrites();
    }

    public function test_search_only_succeeds_when_the_corpus_is_empty(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'query' => 'thoughtful gifts',
                'results' => [],
            ], 200),
        ]);

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'thoughtful gifts',
            '--search-only' => true,
        ])
            ->expectsOutputToContain('SEARCH-ONLY')
            ->expectsOutputToContain('No useful search sources returned.')
            ->assertSuccessful();

        $this->assertNoCatalogWrites();
    }

    public function test_search_only_returns_failure_on_hard_errors(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'detail' => ['error' => 'Unauthorized: missing or invalid API key.'],
            ], 401),
        ]);

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'thoughtful gifts',
            '--search-only' => true,
        ])
            ->expectsOutputToContain('Tavily search is unauthorized')
            ->assertFailed();

        $this->assertNoCatalogWrites();
    }

    public function test_search_only_wins_over_dry_run_and_writes_nothing(): void
    {
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

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'thoughtful gifts',
            '--search-only' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('SEARCH-ONLY')
            ->doesntExpectOutputToContain('Dry run completed')
            ->doesntExpectOutputToContain('Ingestion run')
            ->assertSuccessful();

        $this->assertNoCatalogWrites();
    }

    private function assertNoCatalogWrites(): void
    {
        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateEvidence::query()->count());
        $this->assertSame(0, CatalogCandidateDiscoveryRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionItem::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, AffiliateLink::query()->count());
        $this->assertSame(0, ProductImage::query()->count());
        $this->assertSame(0, ImportRun::query()->count());
    }
}
