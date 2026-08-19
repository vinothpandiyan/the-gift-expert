<?php

namespace Tests\Unit\CommercialSourcing;

use App\CommercialSourcing\TavilyCommercialOfferSearchProvider;
use App\Models\CatalogCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\ConfiguresCommercialSourcing;
use Tests\TestCase;

class TavilyCommercialOfferSearchProviderTest extends TestCase
{
    use ConfiguresCommercialSourcing;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow('2026-08-19 12:00:00');
        $this->useCommercialMerchants([
            'partner-a' => $this->commercialMerchantConfig('partner-a', [
                'domains' => ['partner-a.example'],
                'priority' => 100,
            ]),
        ]);
        $this->createActiveMerchant('partner-a');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_sends_include_domains_and_basic_tavily_search_only(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response($this->tavilyPayload([
                [
                    'title' => 'French Press',
                    'url' => 'https://www.partner-a.example/dp/B0ABCDEFGH',
                    'content' => 'Buy it for ₹1,299',
                ],
            ]), 200),
        ]);

        $candidate = CatalogCandidate::factory()->create(['title' => 'French press']);
        $result = app(TavilyCommercialOfferSearchProvider::class)->search($candidate, 'IN');

        $this->assertCount(2, $result->queries);
        $this->assertSame(['partner-a.example'], $result->metadata['include_domains']);
        $this->assertCount(1, $result->hits);
        $this->assertSame('https://www.partner-a.example/dp/B0ABCDEFGH', $result->hits[0]->url);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.tavily.com/search'
                && $request['search_depth'] === 'basic'
                && $request['include_answer'] === false
                && $request['include_raw_content'] === false
                && $request['include_images'] === false
                && $request['auto_parameters'] === false
                && $request['include_domains'] === ['partner-a.example']
                && ! array_key_exists('include_raw_content_images', $data);
        });
        Http::assertSentCount(2);
    }

    public function test_it_discards_off_allowlist_urls_after_tavily_responds(): void
    {
        config(['commercial_sourcing.search.max_queries_per_candidate' => 1]);

        Http::fake([
            'https://api.tavily.com/search' => Http::response($this->tavilyPayload([
                [
                    'title' => 'Off domain',
                    'url' => 'https://random-blog.test/french-press',
                    'content' => 'Roundup',
                ],
                [
                    'title' => 'On domain',
                    'url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                    'content' => 'Offer',
                ],
            ]), 200),
        ]);

        $candidate = CatalogCandidate::factory()->create(['title' => 'French press']);
        $result = app(TavilyCommercialOfferSearchProvider::class)->search($candidate, 'IN');

        $this->assertCount(1, $result->hits);
        $this->assertSame('https://partner-a.example/dp/B0ABCDEFGH', $result->hits[0]->url);
        $this->assertSame(1, $result->metadata['discarded_off_allowlist']);
    }

    public function test_it_does_not_call_tavily_when_no_search_enabled_domains_exist(): void
    {
        $this->useCommercialMerchants([]);

        Http::fake(function () {
            $this->fail('Tavily must not be called without include_domains.');
        });

        $candidate = CatalogCandidate::factory()->create(['title' => 'French press']);
        $result = app(TavilyCommercialOfferSearchProvider::class)->search($candidate, 'IN');

        $this->assertSame([], $result->hits);
        $this->assertSame([], $result->metadata['include_domains']);
        Http::assertNothingSent();
    }

    public function test_missing_api_key_fails_before_http(): void
    {
        config(['commercial_sourcing.search.providers.tavily.api_key' => '']);

        Http::fake(function () {
            $this->fail('Tavily must not be called without an API key.');
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TAVILY_API_KEY is not configured.');

        app(TavilyCommercialOfferSearchProvider::class)->search(
            CatalogCandidate::factory()->create(['title' => 'French press']),
            'IN',
        );
    }

    public function test_connection_failures_are_hard_failures(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->expectExceptionMessage('timed out or could not connect');

        app(TavilyCommercialOfferSearchProvider::class)->search(
            CatalogCandidate::factory()->create(['title' => 'French press']),
            'IN',
        );
    }

    /**
     * @param  list<mixed>  $results
     * @return array<string, mixed>
     */
    private function tavilyPayload(array $results): array
    {
        return [
            'query' => 'French press buy India',
            'results' => $results,
            'request_id' => 'req-commercial',
        ];
    }
}
