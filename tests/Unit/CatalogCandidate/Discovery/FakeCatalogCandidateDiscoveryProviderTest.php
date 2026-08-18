<?php

namespace Tests\Unit\CatalogCandidate\Discovery;

use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Discovery\FakeCatalogCandidateDiscoveryProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FakeCatalogCandidateDiscoveryProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_loads_the_fixture_corpus_and_candidates_without_http(): void
    {
        $result = app(FakeCatalogCandidateDiscoveryProvider::class)->discover(
            CatalogCandidateResearchBrief::from('Find useful birthday gift ideas for husbands in India'),
        );

        $this->assertNotEmpty($result->queries);
        $this->assertCount(3, $result->corpus);
        $this->assertCount(3, $result->candidates);
        $this->assertSame('https://example.com/husband-birthday-gifts', $result->corpus[0]->url);
        $this->assertSame('example.com', $result->corpus[0]->sourceName);
        $this->assertNotSame('', $result->corpus[0]->snippet);
        $this->assertSame('Portable Photo Printer', $result->candidates[0]['title']);
        $this->assertSame('ai_research', $result->candidates[0]['source_type']);
        $this->assertSame(
            'https://example.com/husband-birthday-gifts',
            $result->candidates[0]['evidence'][0]['source_url'],
        );
    }

    public function test_it_respects_the_brief_candidate_limit(): void
    {
        $result = app(FakeCatalogCandidateDiscoveryProvider::class)->discover(
            CatalogCandidateResearchBrief::from('gifts', maxCandidates: 1),
        );

        $this->assertCount(1, $result->candidates);
        $this->assertCount(3, $result->corpus);
    }
}
