<?php

namespace App\CatalogCandidate\Discovery;

class GroundedCandidateSynthesisPrompt
{
    /**
     * @param  list<RetrievedCatalogCandidateSource>  $sources
     * @param  list<string>  $queries
     * @return array{system: string, user: string, schema: array<string, mixed>}
     */
    public function messages(
        CatalogCandidateResearchBrief $brief,
        array $queries,
        array $sources,
    ): array {
        return [
            'system' => $this->systemInstructions($brief->maxCandidates),
            'user' => $this->userPayload($brief, $queries, $sources),
            'schema' => $this->jsonSchema(),
        ];
    }

    public function systemInstructions(int $maxCandidates): string
    {
        return <<<PROMPT
You synthesize gift-idea catalog candidates from a supplied search corpus.

Produce concrete, purchasable gift IDEA concepts. Do not repeat article titles, roundup headlines, or search-result titles as candidate titles.

Do not output vague ideas such as "something romantic", "personalized surprise", or "a useful gift".

Prefer specific concepts such as: French press, leather wallet, cold brew kit, scented candle jar, manual coffee grinder.

Normalize merchant-specific listing names to generic gift concepts. Merge obvious synonyms. Preserve materially distinct gift types.

Use only the provided sources. Copy each evidence source_url exactly from the supplied source list. Do not invent, rewrite, shorten, or guess URLs.

Do not claim that an idea is trending or popular unless a supplied snippet supports that claim.

Community sources may support real-user interest. Editorial and specialist sources support curated ideas. Merchant pages support that a product exists or is available, not popularity. Pinterest and Instagram are weaker evidence. Marketplace search pages are not proof of popularity.

Propose at most {$maxCandidates} candidates. Prefer two independent hosts when the corpus supports it; one grounded evidence URL is enough.

Output structured JSON only. No markdown.
PROMPT;
    }

    /**
     * @param  list<string>  $queries
     * @param  list<RetrievedCatalogCandidateSource>  $sources
     */
    public function userPayload(
        CatalogCandidateResearchBrief $brief,
        array $queries,
        array $sources,
    ): string {
        $payload = [
            'brief' => $brief->brief,
            'market' => $brief->market,
            'max_candidates' => $brief->maxCandidates,
            'freshness_days' => $brief->freshnessDays,
            'queries' => $queries,
            'sources' => array_map(fn (RetrievedCatalogCandidateSource $source): array => [
                'url' => $source->url,
                'title' => $source->title,
                'snippet' => $source->snippet,
                'source_name' => $source->sourceName,
            ], $sources),
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'candidates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                            'evidence' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'source_url' => ['type' => 'string'],
                                        'summary' => ['type' => 'string'],
                                    ],
                                    'required' => ['source_url', 'summary'],
                                ],
                            ],
                        ],
                        'required' => ['title', 'summary', 'evidence'],
                    ],
                ],
            ],
            'required' => ['candidates'],
        ];
    }
}
