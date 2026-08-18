<?php

namespace Tests\Unit\Actions\CatalogCandidate;

use App\Actions\CatalogCandidate\SearchCatalogCandidateSourcesAction;
use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Discovery\TavilyCatalogCandidateSearchProvider;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateEvidence;
use App\Models\CatalogCandidateIngestionItem;
use App\Models\CatalogCandidateIngestionRun;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class SearchCatalogCandidateSourcesActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'catalog_candidate_discovery.search.providers.tavily.api_key' => 'tvly-test-key',
            'catalog_candidate_discovery.search.max_queries_per_brief' => 1,
        ]);
    }

    public function test_it_resolves_the_configured_tavily_provider_without_database_writes(): void
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

        $action = app(SearchCatalogCandidateSourcesAction::class);

        $this->assertInstanceOf(TavilyCatalogCandidateSearchProvider::class, $action->resolveProvider());

        $result = $action->execute(CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'));

        $this->assertCount(1, $result->corpus);
        $this->assertSame('https://example.com/gifts', $result->corpus[0]->url);
        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateEvidence::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionItem::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, AffiliateLink::query()->count());
        $this->assertSame(0, ProductImage::query()->count());
        $this->assertSame(0, ImportRun::query()->count());
    }

    public function test_an_unknown_search_provider_fails(): void
    {
        config(['catalog_candidate_discovery.search.provider' => 'missing']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown catalog candidate search provider [missing].');

        app(SearchCatalogCandidateSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );
    }
}
