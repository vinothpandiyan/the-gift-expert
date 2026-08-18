<?php

namespace Tests\Unit\CatalogCandidate\Discovery;

use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Discovery\WebResearchCatalogCandidateDiscoveryProvider;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateDiscoveryRun;
use App\Models\CatalogCandidateEvidence;
use App\Models\CatalogCandidateIngestionItem;
use App\Models\CatalogCandidateIngestionRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesCatalogCandidateWebResearch;
use Tests\TestCase;

class WebResearchCatalogCandidateDiscoveryProviderTest extends TestCase
{
    use FakesCatalogCandidateWebResearch;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureWebResearch();
    }

    public function test_it_searches_then_synthesizes_without_writing(): void
    {
        $this->fakeWebResearchHttp($this->defaultTavilyResults(), $this->defaultSynthesisCandidates());

        $result = app(WebResearchCatalogCandidateDiscoveryProvider::class)->discover(
            CatalogCandidateResearchBrief::from('Find gift ideas for coffee lovers in India', 'IN', 10),
        );

        $this->assertSame('French press', $result->candidates[0]['title']);
        $this->assertNotSame([], $result->queries);
        $this->assertCount(2, $result->corpus);
        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateEvidence::query()->count());
        $this->assertSame(0, CatalogCandidateDiscoveryRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionItem::query()->count());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/chat/completions');
        Http::assertSentCount(2);
    }

    public function test_empty_corpus_skips_the_llm(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'tavily')) {
                return Http::response(['results' => []], 200);
            }

            $this->fail('LLM must not be called for an empty corpus.');
        });

        $result = app(WebResearchCatalogCandidateDiscoveryProvider::class)->discover(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );

        $this->assertSame([], $result->candidates);
        $this->assertSame([], $result->corpus);
        $this->assertNotSame([], $result->queries);
        $this->assertSame('skipped_empty_corpus', $result->metadata['synthesis']);
        $this->assertSame(0, CatalogCandidateDiscoveryRun::query()->count());
        Http::assertSentCount(1);
    }
}
