<?php

namespace Tests\Unit\CatalogCandidate\Discovery;

use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Discovery\CatalogCandidateSearchException;
use App\CatalogCandidate\Discovery\TavilyCatalogCandidateSearchProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class TavilyCatalogCandidateSearchProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow('2026-08-19 12:00:00');
        config([
            'catalog_candidate_discovery.search.providers.tavily.api_key' => 'tvly-test-key',
            'catalog_candidate_discovery.search.max_queries_per_brief' => 2,
            'catalog_candidate_discovery.search.max_results_per_query' => 8,
            'catalog_candidate_discovery.search.snippet_max_length' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_maps_valid_results_and_sends_bounded_search_parameters(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response($this->tavilyPayload([
                [
                    'title' => '  Gift roundup  ',
                    'url' => 'https://www.example.com/gifts',
                    'content' => '  A useful snippet that is longer than twenty characters.  ',
                ],
            ]), 200),
        ]);

        $result = app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN', freshnessDays: 30),
        );

        $this->assertCount(2, $result->queries);
        $this->assertCount(1, $result->corpus);
        $this->assertSame('https://www.example.com/gifts', $result->corpus[0]->url);
        $this->assertSame('Gift roundup', $result->corpus[0]->title);
        $this->assertSame('example.com', $result->corpus[0]->sourceName);
        $this->assertSame('A useful snippet tha', $result->corpus[0]->snippet);
        $this->assertTrue(Carbon::parse($result->corpus[0]->retrievedAt)->equalTo(now()));
        $this->assertSame('2026-07-20', $result->metadata['start_date']);
        $this->assertSame('india', $result->metadata['country']);
        $this->assertSame(0, $result->metadata['invalid_result_count']);

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.tavily.com/search'
                && $request->hasHeader('Authorization', 'Bearer tvly-test-key')
                && $request['search_depth'] === 'basic'
                && $request['topic'] === 'general'
                && $request['max_results'] === 8
                && $request['include_answer'] === false
                && $request['include_raw_content'] === false
                && $request['include_images'] === false
                && $request['auto_parameters'] === false
                && ! array_key_exists('include_domains', $request->data())
                && $request['start_date'] === '2026-07-20'
                && $request['country'] === 'india'
                && is_string($request['query'])
                && $request['query'] !== '';
        });
    }

    public function test_it_omits_country_when_the_market_is_not_a_tavily_country(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response($this->tavilyPayload([]), 200),
        ]);

        $result = app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'AQ'),
        );

        $this->assertNull($result->metadata['country']);

        Http::assertSent(function ($request): bool {
            return ! array_key_exists('country', $request->data());
        });
    }

    public function test_it_collapses_duplicate_urls_across_queries_and_skips_invalid_items(): void
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response($this->tavilyPayload([
                    [
                        'title' => 'First',
                        'url' => 'https://Example.com/gifts/#top',
                        'content' => 'Keep me',
                    ],
                    [
                        'title' => 'Bad',
                        'url' => 'not-a-url',
                        'content' => 'skip',
                    ],
                    ['not' => 'an object'],
                    [
                        'title' => 'File',
                        'url' => 'file:///tmp/secret',
                        'content' => 'skip',
                    ],
                ]), 200);
            }

            return Http::response($this->tavilyPayload([
                [
                    'title' => 'Duplicate',
                    'url' => 'https://example.com/gifts/',
                    'content' => 'later duplicate',
                ],
                [
                    'title' => '',
                    'url' => 'https://roundups.test/list',
                    'content' => 'second page',
                ],
            ]), 200);
        });

        $result = app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );

        $this->assertCount(2, $result->corpus);
        $this->assertSame('https://Example.com/gifts/#top', $result->corpus[0]->url);
        $this->assertSame('First', $result->corpus[0]->title);
        $this->assertSame('https://roundups.test/list', $result->corpus[1]->url);
        $this->assertSame('roundups.test', $result->corpus[1]->title);
        $this->assertSame(3, $result->metadata['invalid_result_count']);
    }

    public function test_empty_results_are_a_successful_empty_corpus(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response($this->tavilyPayload([]), 200),
        ]);

        $result = app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );

        $this->assertSame([], $result->corpus);
        $this->assertSame(0, $result->metadata['invalid_result_count']);
    }

    public function test_all_invalid_results_are_a_successful_empty_corpus(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response($this->tavilyPayload([
                ['title' => 'Bad', 'url' => '', 'content' => 'x'],
            ]), 200),
        ]);

        $result = app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );

        $this->assertSame([], $result->corpus);
        $this->assertGreaterThan(0, $result->metadata['invalid_result_count']);
    }

    public function test_http_401_is_a_hard_failure(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'detail' => ['error' => 'Unauthorized: missing or invalid API key.'],
            ], 401),
        ]);

        $this->expectException(CatalogCandidateSearchException::class);
        $this->expectExceptionMessage('Tavily search is unauthorized');

        app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );
    }

    public function test_http_403_is_a_hard_failure(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response(['detail' => ['error' => 'Forbidden']], 403),
        ]);

        $this->expectException(CatalogCandidateSearchException::class);
        $this->expectExceptionMessage('Tavily search is forbidden');

        app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );
    }

    public function test_http_429_fails_immediately_and_does_not_continue_queries(): void
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response([
                'detail' => ['error' => 'Please reduce rate of requests.'],
            ], 429);
        });

        try {
            app(TavilyCatalogCandidateSearchProvider::class)->search(
                CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
            );
            $this->fail('Expected CatalogCandidateSearchException was not thrown.');
        } catch (CatalogCandidateSearchException $exception) {
            $this->assertStringContainsString('rate limited', $exception->getMessage());
            $this->assertStringContainsString('Please reduce rate of requests.', $exception->getMessage());
        }

        $this->assertSame(1, $calls);
    }

    public function test_http_432_is_a_hard_failure(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'detail' => ['error' => 'This request exceeds your plan\'s set usage limit.'],
            ], 432),
        ]);

        $this->expectException(CatalogCandidateSearchException::class);
        $this->expectExceptionMessage('plan or usage limit');

        app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );
    }

    public function test_http_433_is_a_hard_failure(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'detail' => ['error' => 'This request exceeds the pay-as-you-go limit.'],
            ], 433),
        ]);

        $this->expectException(CatalogCandidateSearchException::class);
        $this->expectExceptionMessage('plan or usage limit');

        app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );
    }

    public function test_http_500_is_a_hard_failure(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response(['detail' => ['error' => 'Internal Server Error']], 500),
        ]);

        $this->expectException(CatalogCandidateSearchException::class);
        $this->expectExceptionMessage('server error');

        app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );
    }

    public function test_connection_failures_are_hard_failures(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->expectException(CatalogCandidateSearchException::class);
        $this->expectExceptionMessage('timed out or could not connect');

        app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );
    }

    public function test_malformed_json_is_a_hard_failure(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response('not-json', 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        $this->expectException(CatalogCandidateSearchException::class);
        $this->expectExceptionMessage('malformed');

        app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );
    }

    public function test_missing_results_is_a_hard_failure(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response(['query' => 'thoughtful gifts'], 200),
        ]);

        $this->expectException(CatalogCandidateSearchException::class);
        $this->expectExceptionMessage('malformed');

        app(TavilyCatalogCandidateSearchProvider::class)->search(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );
    }

    public function test_a_missing_api_key_fails_before_http(): void
    {
        config(['catalog_candidate_discovery.search.providers.tavily.api_key' => '']);

        Http::fake(function () {
            $this->fail('Tavily must not be called without an API key.');
        });

        try {
            app(TavilyCatalogCandidateSearchProvider::class)->search(
                CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
            );
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('TAVILY_API_KEY is not configured.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * @param  list<mixed>  $results
     * @return array<string, mixed>
     */
    private function tavilyPayload(array $results): array
    {
        return [
            'query' => 'thoughtful gifts',
            'results' => $results,
            'request_id' => 'req-test',
        ];
    }
}
