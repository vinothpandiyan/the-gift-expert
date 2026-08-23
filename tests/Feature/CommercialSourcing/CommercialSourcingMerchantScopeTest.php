<?php

namespace Tests\Feature\CommercialSourcing;

use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Discovery\TavilyCatalogCandidateSearchProvider;
use App\CommercialSourcing\CommercialSourcingMerchants;
use App\CommercialSourcing\TavilyCommercialOfferSearchProvider;
use App\Models\CatalogCandidate;
use Database\Seeders\MerchantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommercialSourcingMerchantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->seed(MerchantSeeder::class);
    }

    public function test_only_amazon_is_searchable_for_commercial_sourcing_in_india(): void
    {
        $merchants = app(CommercialSourcingMerchants::class);

        $this->assertSame(['amazon-in'], app(CommercialSourcingMerchants::class)
            ->searchableConfigs('IN')
            ->keys()
            ->all());
        $this->assertSame(['amazon.in'], $merchants->includeDomains('IN'));
    }

    public function test_non_amazon_merchants_remain_configured_but_are_not_searchable(): void
    {
        foreach (['fnp', 'flipkart', 'myntra'] as $slug) {
            $config = config('commercial_sourcing.merchants.'.$slug);

            $this->assertIsArray($config);
            $this->assertTrue($config['enabled'] ?? false);
            $this->assertFalse($config['search_enabled'] ?? true);
            $this->assertFalse($config['affiliate_enabled'] ?? true);
            $this->assertSame('manual', $config['affiliate_strategy'] ?? null);
            $this->assertNotSame([], $config['domains'] ?? []);
        }

        $resolver = app(CommercialSourcingMerchants::class);

        $this->assertNull(
            $resolver->resolveFromUrl('https://www.flipkart.com/sample/p/itm123', 'IN'),
        );
        $this->assertNull(
            $resolver->resolveFromUrl('https://www.fnp.com/gift/sample-product', 'IN'),
        );
        $this->assertNull(
            $resolver->resolveFromUrl('https://www.myntra.com/shirts/sample/123', 'IN'),
        );
    }

    public function test_commercial_tavily_search_sends_amazon_in_only(): void
    {
        config([
            'commercial_sourcing.search.providers.tavily.api_key' => 'tvly-commercial-key',
            'commercial_sourcing.search.max_queries_per_candidate' => 1,
        ]);

        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => [
                    [
                        'title' => 'Sample Gift',
                        'url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                        'content' => '₹1,299',
                    ],
                ],
            ], 200),
        ]);

        $candidate = CatalogCandidate::factory()->create(['title' => 'French press']);
        $result = app(TavilyCommercialOfferSearchProvider::class)->search($candidate, 'IN');

        $this->assertSame(['amazon.in'], $result->metadata['include_domains']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.tavily.com/search'
                && $request['include_domains'] === ['amazon.in'];
        });
    }

    public function test_discovery_tavily_search_remains_unrestricted(): void
    {
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
}
