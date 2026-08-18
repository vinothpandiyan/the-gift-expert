<?php

namespace Tests\Feature\CatalogCandidate;

use App\Actions\CatalogCandidate\CreateCatalogCandidateAction;
use App\Enums\CatalogCandidateDiscoveryRunStatus;
use App\Enums\CatalogCandidateIngestionItemStatus;
use App\Enums\CatalogCandidateSourceType;
use App\Enums\CatalogCandidateStatus;
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
use Tests\Support\FakesCatalogCandidateWebResearch;
use Tests\TestCase;

class DiscoverCatalogCandidatesWebResearchTest extends TestCase
{
    use FakesCatalogCandidateWebResearch;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureWebResearch();
    }

    public function test_dry_run_searches_synthesizes_and_writes_nothing(): void
    {
        $this->fakeWebResearchHttp($this->defaultTavilyResults(), $this->defaultSynthesisCandidates());

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'Find gift ideas for coffee lovers in India',
            '--market' => 'IN',
            '--max' => 10,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run completed. No catalog candidates were written.')
            ->expectsOutputToContain('French press')
            ->expectsOutputToContain('https://www.example.com/coffee-gifts')
            ->expectsOutputToContain('Queries:')
            ->assertSuccessful();

        $this->assertNoDiscoveryWrites();
        $this->assertCatalogUntouched();
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/chat/completions');
    }

    public function test_live_discovery_creates_candidates_evidence_and_a_linked_run(): void
    {
        $this->fakeWebResearchHttp($this->defaultTavilyResults(), $this->defaultSynthesisCandidates());

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'Find gift ideas for coffee lovers in India',
            '--market' => 'IN',
            '--max' => 10,
        ])
            ->expectsOutputToContain('French press')
            ->expectsOutputToContain('succeeded')
            ->assertSuccessful();

        $this->assertSame(2, CatalogCandidate::query()->count());
        $this->assertGreaterThanOrEqual(2, CatalogCandidateEvidence::query()->count());
        $this->assertSame(CatalogCandidateStatus::Discovered, CatalogCandidate::query()->first()->status);
        $this->assertSame(CatalogCandidateSourceType::AiResearch, CatalogCandidate::query()->first()->source_type);

        $discoveryRun = CatalogCandidateDiscoveryRun::query()->first();
        $ingestionRun = CatalogCandidateIngestionRun::query()->first();

        $this->assertNotNull($discoveryRun);
        $this->assertNotNull($ingestionRun);
        $this->assertSame('web_research', $discoveryRun->provider_key);
        $this->assertSame(CatalogCandidateDiscoveryRunStatus::Completed, $discoveryRun->status);
        $this->assertSame($ingestionRun->id, $discoveryRun->catalog_candidate_ingestion_run_id);
        $this->assertSame(2, $discoveryRun->candidates_proposed);
        $this->assertNotSame([], $discoveryRun->queries);
        $this->assertContains('https://www.example.com/coffee-gifts', $discoveryRun->retrieved_urls);
        $this->assertCatalogUntouched();
        $this->assertSame(14, $this->discoveryRouteCount());
    }

    public function test_inbox_duplicates_are_skipped_without_completed_with_errors(): void
    {
        app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'French press',
            'source_type' => CatalogCandidateSourceType::Manual,
        ]);

        $this->fakeWebResearchHttp($this->defaultTavilyResults(), $this->defaultSynthesisCandidates());

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'Find gift ideas for coffee lovers in India',
        ])->assertSuccessful();

        $this->assertSame(2, CatalogCandidate::query()->count());
        $this->assertSame(CatalogCandidateDiscoveryRunStatus::Completed, CatalogCandidateDiscoveryRun::query()->first()->status);
        $this->assertSame(1, CatalogCandidateIngestionItem::query()->where('status', 'skipped')->count());
    }

    public function test_empty_corpus_completes_without_ingestion_or_llm(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'tavily')) {
                return Http::response(['results' => []], 200);
            }

            $this->fail('LLM must not be called for an empty corpus.');
        });

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'thoughtful gifts',
        ])
            ->expectsOutputToContain('Total: 0')
            ->assertSuccessful();

        $run = CatalogCandidateDiscoveryRun::query()->first();

        $this->assertNotNull($run);
        $this->assertSame(CatalogCandidateDiscoveryRunStatus::Completed, $run->status);
        $this->assertSame(0, $run->candidates_proposed);
        $this->assertNull($run->catalog_candidate_ingestion_run_id);
        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        Http::assertSentCount(1);
    }

    public function test_search_failure_marks_the_discovery_run_failed(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'detail' => ['error' => 'Unauthorized: missing or invalid API key.'],
            ], 401),
        ]);

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'thoughtful gifts',
        ])
            ->expectsOutputToContain('Tavily search is unauthorized')
            ->assertFailed();

        $run = CatalogCandidateDiscoveryRun::query()->first();

        $this->assertNotNull($run);
        $this->assertSame(CatalogCandidateDiscoveryRunStatus::Failed, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        $this->assertSame(0, CatalogCandidate::query()->count());
    }

    public function test_synthesis_failure_marks_the_discovery_run_failed(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => $this->defaultTavilyResults(),
            ], 200),
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Internal Server Error'],
            ], 500),
        ]);

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'thoughtful gifts',
        ])
            ->expectsOutputToContain('server error')
            ->assertFailed();

        $run = CatalogCandidateDiscoveryRun::query()->first();

        $this->assertSame(CatalogCandidateDiscoveryRunStatus::Failed, $run->status);
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
    }

    public function test_malformed_synthesis_does_not_partially_ingest(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => $this->defaultTavilyResults(),
            ], 200),
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{not-json']]],
            ], 200),
        ]);

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'thoughtful gifts',
        ])->assertFailed();

        $this->assertSame(CatalogCandidateDiscoveryRunStatus::Failed, CatalogCandidateDiscoveryRun::query()->first()->status);
        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
    }

    public function test_invalid_proposals_continue_and_mark_completed_with_errors(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => $this->defaultTavilyResults(),
            ], 200),
            'https://api.openai.com/v1/chat/completions' => Http::response($this->openaiCompletion([
                $this->defaultSynthesisCandidates()[0],
                [
                    'title' => 'Invented Gadget',
                    'summary' => 'Not grounded.',
                    'evidence' => [[
                        'source_url' => 'https://invented.example.com/nope',
                        'summary' => 'Fabricated',
                    ]],
                ],
            ])),
        ]);

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'thoughtful gifts',
        ])->assertSuccessful();

        $this->assertSame(1, CatalogCandidate::query()->count());
        $this->assertSame('French press', CatalogCandidate::query()->value('title'));
        $this->assertSame(
            CatalogCandidateDiscoveryRunStatus::CompletedWithErrors,
            CatalogCandidateDiscoveryRun::query()->first()->status,
        );
        $this->assertSame(CatalogCandidateIngestionItemStatus::Failed, CatalogCandidateIngestionItem::query()->where('title', 'Invented Gadget')->first()->status);
    }

    public function test_zero_synthesized_candidates_complete_without_ingestion(): void
    {
        $this->fakeWebResearchHttp($this->defaultTavilyResults(), []);

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'thoughtful gifts',
        ])->assertSuccessful();

        $run = CatalogCandidateDiscoveryRun::query()->first();

        $this->assertSame(CatalogCandidateDiscoveryRunStatus::Completed, $run->status);
        $this->assertSame(0, $run->candidates_proposed);
        $this->assertNull($run->catalog_candidate_ingestion_run_id);
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
    }

    public function test_search_only_still_skips_synthesis_and_discovery_runs(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'tavily')) {
                return Http::response([
                    'results' => $this->defaultTavilyResults(),
                ], 200);
            }

            $this->fail('Search-only must not call the LLM.');
        });

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'thoughtful gifts',
            '--search-only' => true,
        ])
            ->expectsOutputToContain('SEARCH-ONLY')
            ->assertSuccessful();

        $this->assertNoDiscoveryWrites();
    }

    private function assertNoDiscoveryWrites(): void
    {
        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateEvidence::query()->count());
        $this->assertSame(0, CatalogCandidateDiscoveryRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionItem::query()->count());
    }

    private function assertCatalogUntouched(): void
    {
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, AffiliateLink::query()->count());
        $this->assertSame(0, ProductImage::query()->count());
        $this->assertSame(0, ImportRun::query()->count());
        $this->assertSame(0, DB::table('category_product')->count());
        $this->assertSame(0, DB::table('occasion_product')->count());
        $this->assertSame(0, DB::table('relationship_product')->count());
        $this->assertSame(0, DB::table('recipient_type_product')->count());
        $this->assertSame(0, DB::table('interest_product')->count());
        $this->assertSame(0, DB::table('profession_product')->count());
        $this->assertSame(0, DB::table('gift_type_product')->count());
    }

    private function discoveryRouteCount(): int
    {
        return collect(Route::getRoutes())->filter(
            fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'),
        )->count();
    }
}
