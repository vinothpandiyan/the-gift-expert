<?php

namespace Tests\Unit\Actions\CatalogCandidate;

use App\Actions\CatalogCandidate\GroundDiscoveredCandidatesAction;
use App\Actions\CatalogCandidate\SynthesizeCatalogCandidatesFromSourcesAction;
use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Discovery\CatalogCandidateSearchResult;
use App\CatalogCandidate\Discovery\CatalogCandidateSynthesisException;
use App\CatalogCandidate\Discovery\RetrievedCatalogCandidateSource;
use App\CatalogCandidate\Ingestion\IngestedCatalogCandidate;
use App\CatalogCandidate\Ingestion\IngestionRowError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\FakesCatalogCandidateWebResearch;
use Tests\TestCase;

class SynthesizeCatalogCandidatesFromSourcesActionTest extends TestCase
{
    use FakesCatalogCandidateWebResearch;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureWebResearch();
    }

    public function test_it_hydrates_stamped_fields_and_preserves_evidence_urls(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->openaiCompletion($this->defaultSynthesisCandidates()),
            ),
        ]);

        $result = app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('Find gift ideas for coffee lovers in India', maxCandidates: 10),
            $this->searchResult(),
        );

        $this->assertCount(2, $result->candidates);
        $this->assertSame('French press', $result->candidates[0]['title']);
        $this->assertSame('ai_research', $result->candidates[0]['source_type']);
        $this->assertSame('Gift Candidate Research', $result->candidates[0]['source_name']);
        $this->assertSame('normal', $result->candidates[0]['priority']);
        $this->assertNull($result->candidates[0]['source_url']);
        $this->assertNull($result->candidates[0]['external_reference']);
        $this->assertSame('web', $result->candidates[0]['evidence'][0]['source_type']);
        $this->assertSame('example.com', $result->candidates[0]['evidence'][0]['source_name']);
        $this->assertSame('https://www.example.com/coffee-gifts', $result->candidates[0]['evidence'][0]['source_url']);
        $this->assertSame('French press', $result->candidates[0]['title']);
        $this->assertNotSame('Best coffee gifts for home brewing', $result->candidates[0]['title']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && ($data['response_format']['type'] ?? null) === 'json_schema'
                && ($data['max_completion_tokens'] ?? null) === 4000
                && ! array_key_exists('max_tokens', $data)
                && ! array_key_exists('temperature', $data)
                && ! array_key_exists('tools', $data)
                && ! array_key_exists('web_search_options', $data);
        });
    }

    public function test_it_includes_temperature_only_when_configured(): void
    {
        config(['catalog_candidate_discovery.synthesis.temperature' => 0.2]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->openaiCompletion([$this->defaultSynthesisCandidates()[0]]),
            ),
        ]);

        app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('coffee gifts', maxCandidates: 1),
            $this->searchResult(),
        );

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && ($data['max_completion_tokens'] ?? null) === 4000
                && ! array_key_exists('max_tokens', $data)
                && ($data['temperature'] ?? null) === 0.2;
        });
    }

    public function test_it_canonicalizes_equivalent_evidence_urls(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response($this->openaiCompletion([
                [
                    'title' => 'French press',
                    'summary' => 'A classic brew method.',
                    'evidence' => [[
                        'source_url' => 'https://www.EXAMPLE.com/coffee-gifts/',
                        'summary' => 'Roundup',
                    ]],
                ],
            ])),
        ]);

        $result = app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('coffee gifts', maxCandidates: 5),
            $this->searchResult(),
        );

        $this->assertSame(
            'https://www.example.com/coffee-gifts',
            $result->candidates[0]['evidence'][0]['source_url'],
        );
    }

    public function test_it_remaps_amazon_www_host_variants_to_the_corpus_url(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response($this->openaiCompletion([
                [
                    'title' => 'Brass diya or kumkum holder',
                    'summary' => 'A traditional return-gift item.',
                    'evidence' => [[
                        'source_url' => 'https://amazon.in/example?x=1',
                        'summary' => 'Marketplace listing',
                    ]],
                ],
            ])),
        ]);

        $search = new CatalogCandidateSearchResult(
            corpus: [
                new RetrievedCatalogCandidateSource(
                    url: 'https://www.amazon.in/example?x=1',
                    title: 'Marriage return gifts under Rs 500',
                    snippet: 'Brass diya listings.',
                    sourceName: 'amazon.in',
                    retrievedAt: now(),
                ),
            ],
            queries: ['wedding return gifts'],
            metadata: ['provider' => 'tavily'],
        );

        $result = app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('wedding return gifts', maxCandidates: 5),
            $search,
        );

        $this->assertSame(
            'https://www.amazon.in/example?x=1',
            $result->candidates[0]['evidence'][0]['source_url'],
        );

        $rows = app(GroundDiscoveredCandidatesAction::class)->execute($result);

        $this->assertInstanceOf(IngestedCatalogCandidate::class, $rows[0]);
        $this->assertSame('https://www.amazon.in/example?x=1', $rows[0]->evidence[0]->sourceUrl);
    }

    public function test_invented_urls_are_left_for_existing_grounding_to_reject(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response($this->openaiCompletion([
                [
                    'title' => 'Invented Gadget',
                    'summary' => 'Not in the corpus.',
                    'evidence' => [[
                        'source_url' => 'https://invented.example.com/nope',
                        'summary' => 'Fabricated',
                    ]],
                ],
            ])),
        ]);

        $discovered = app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('coffee gifts', maxCandidates: 5),
            $this->searchResult(),
        );

        $rows = app(GroundDiscoveredCandidatesAction::class)->execute($discovered);

        $this->assertInstanceOf(IngestionRowError::class, $rows[0]);
        $this->assertSame('Evidence URLs must match a retrieved source URL.', $rows[0]->message);
    }

    public function test_missing_evidence_is_rejected_by_existing_grounding(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response($this->openaiCompletion([
                [
                    'title' => 'French press',
                    'summary' => 'A classic brew method.',
                    'evidence' => [],
                ],
            ])),
        ]);

        $discovered = app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('coffee gifts', maxCandidates: 5),
            $this->searchResult(),
        );

        $rows = app(GroundDiscoveredCandidatesAction::class)->execute($discovered);

        $this->assertInstanceOf(IngestionRowError::class, $rows[0]);
        $this->assertSame(
            'Discovered candidates must include at least one evidence URL from the retrieved sources.',
            $rows[0]->message,
        );
    }

    public function test_it_caps_candidates_to_the_brief_maximum(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->openaiCompletion($this->defaultSynthesisCandidates()),
            ),
        ]);

        $result = app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('coffee gifts', maxCandidates: 1),
            $this->searchResult(),
        );

        $this->assertCount(1, $result->candidates);
        $this->assertSame('French press', $result->candidates[0]['title']);
    }

    public function test_it_bounds_the_source_corpus_sent_to_the_model(): void
    {
        config(['catalog_candidate_discovery.synthesis.max_sources' => 1]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->openaiCompletion([$this->defaultSynthesisCandidates()[0]]),
            ),
        ]);

        app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('coffee gifts', maxCandidates: 5),
            $this->searchResult(),
        );

        Http::assertSent(function ($request): bool {
            $user = $request['messages'][1]['content'] ?? '';

            return is_string($user)
                && str_contains($user, 'https://www.example.com/coffee-gifts')
                && ! str_contains($user, 'https://www.reddit.com/r/coffee/comments/gifts');
        });
    }

    public function test_malformed_json_is_a_hard_failure(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{not-json']]],
            ]),
        ]);

        $this->expectException(CatalogCandidateSynthesisException::class);
        $this->expectExceptionMessage('malformed');

        app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('coffee gifts'),
            $this->searchResult(),
        );
    }

    public function test_http_401_is_a_hard_failure(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Invalid API key'],
            ], 401),
        ]);

        $this->expectException(CatalogCandidateSynthesisException::class);
        $this->expectExceptionMessage('unauthorized');

        app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('coffee gifts'),
            $this->searchResult(),
        );
    }

    public function test_http_429_is_a_hard_failure(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Rate limited'],
            ], 429),
        ]);

        $this->expectException(CatalogCandidateSynthesisException::class);
        $this->expectExceptionMessage('rate limited');

        app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('coffee gifts'),
            $this->searchResult(),
        );
    }

    public function test_http_500_is_a_hard_failure(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Internal Server Error'],
            ], 500),
        ]);

        $this->expectException(CatalogCandidateSynthesisException::class);
        $this->expectExceptionMessage('server error');

        app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('coffee gifts'),
            $this->searchResult(),
        );
    }

    public function test_timeouts_are_hard_failures(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->expectException(CatalogCandidateSynthesisException::class);
        $this->expectExceptionMessage('timed out or could not connect');

        app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
            CatalogCandidateResearchBrief::from('coffee gifts'),
            $this->searchResult(),
        );
    }

    public function test_a_missing_api_key_fails_before_http(): void
    {
        config(['catalog_candidate_discovery.synthesis.api_key' => '']);

        Http::fake(function () {
            $this->fail('Synthesis must not be called without an API key.');
        });

        try {
            app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
                CatalogCandidateResearchBrief::from('coffee gifts'),
                $this->searchResult(),
            );
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('OPENAI_API_KEY is not configured.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_missing_model_fails_before_http(): void
    {
        config(['catalog_candidate_discovery.synthesis.model' => '']);

        Http::fake(function () {
            $this->fail('Synthesis must not be called without a model.');
        });

        try {
            app(SynthesizeCatalogCandidatesFromSourcesAction::class)->execute(
                CatalogCandidateResearchBrief::from('coffee gifts'),
                $this->searchResult(),
            );
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('OPENAI_MODEL is not configured.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    private function searchResult(): CatalogCandidateSearchResult
    {
        return new CatalogCandidateSearchResult(
            corpus: [
                new RetrievedCatalogCandidateSource(
                    url: 'https://www.example.com/coffee-gifts',
                    title: 'Best coffee gifts for home brewing',
                    snippet: 'A French press and a manual coffee grinder are practical gifts.',
                    sourceName: 'example.com',
                    retrievedAt: now(),
                ),
                new RetrievedCatalogCandidateSource(
                    url: 'https://www.reddit.com/r/coffee/comments/gifts',
                    title: 'Reddit thread about coffee presents',
                    snippet: 'People recommended a cold brew kit.',
                    sourceName: 'reddit.com',
                    retrievedAt: now(),
                ),
            ],
            queries: ['Find gift ideas for coffee lovers in India'],
            metadata: ['provider' => 'tavily'],
        );
    }
}
