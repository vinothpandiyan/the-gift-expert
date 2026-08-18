<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

trait FakesCatalogCandidateWebResearch
{
    /**
     * @param  list<array<string, mixed>>  $tavilyResults
     * @param  list<array<string, mixed>>  $candidates
     */
    protected function fakeWebResearchHttp(array $tavilyResults, array $candidates, int $openaiStatus = 200): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'query' => 'gifts',
                'results' => $tavilyResults,
            ], 200),
            'https://api.openai.com/v1/chat/completions' => Http::response($this->openaiCompletion($candidates), $openaiStatus),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    protected function openaiCompletion(array $candidates): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode(['candidates' => $candidates], JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function defaultTavilyResults(): array
    {
        return [
            [
                'title' => 'Best coffee gifts for home brewing',
                'url' => 'https://www.example.com/coffee-gifts',
                'content' => 'A French press and a manual coffee grinder are practical gifts for coffee lovers.',
            ],
            [
                'title' => 'Reddit thread about coffee presents',
                'url' => 'https://www.reddit.com/r/coffee/comments/gifts',
                'content' => 'People recommended a cold brew kit and a ceramic pour-over dripper.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function defaultSynthesisCandidates(): array
    {
        return [
            [
                'title' => 'French press',
                'summary' => 'A classic brew method for people who enjoy coffee at home.',
                'evidence' => [
                    [
                        'source_url' => 'https://www.example.com/coffee-gifts',
                        'summary' => 'Editorial roundup recommends a French press.',
                    ],
                ],
            ],
            [
                'title' => 'Manual coffee grinder',
                'summary' => 'A compact grinder for fresher beans without a bulky electric unit.',
                'evidence' => [
                    [
                        'source_url' => 'https://www.example.com/coffee-gifts',
                        'summary' => 'Manual grinders are listed as practical coffee gifts.',
                    ],
                    [
                        'source_url' => 'https://www.reddit.com/r/coffee/comments/gifts',
                        'summary' => 'Community replies mention grinders alongside brew kits.',
                    ],
                ],
            ],
        ];
    }

    protected function configureWebResearch(): void
    {
        config([
            'catalog_candidate_discovery.provider' => 'web_research',
            'catalog_candidate_discovery.search.provider' => 'tavily',
            'catalog_candidate_discovery.search.providers.tavily.api_key' => 'tvly-test-key',
            'catalog_candidate_discovery.search.max_queries_per_brief' => 1,
            'catalog_candidate_discovery.synthesis.api_key' => 'sk-test-key',
            'catalog_candidate_discovery.synthesis.model' => 'test-model',
        ]);
    }
}
