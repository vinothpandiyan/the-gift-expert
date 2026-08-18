<?php

namespace App\Actions\CatalogCandidate;

use App\CatalogCandidate\Discovery\CatalogCandidateDiscoveryResult;
use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Discovery\CatalogCandidateSearchResult;
use App\CatalogCandidate\Discovery\CatalogCandidateSynthesisException;
use App\CatalogCandidate\Discovery\GroundedCandidateSynthesisPrompt;
use App\CatalogCandidate\Discovery\OpenAiCompatibleCatalogCandidateSynthesisClient;
use App\CatalogCandidate\Discovery\RetrievedCatalogCandidateSource;
use App\Enums\CatalogCandidatePriority;
use App\Enums\CatalogCandidateSourceType;
use App\Support\CatalogCandidateSourceUrl;

class SynthesizeCatalogCandidatesFromSourcesAction
{
    public function execute(
        CatalogCandidateResearchBrief $brief,
        CatalogCandidateSearchResult $search,
    ): CatalogCandidateDiscoveryResult {
        $bounded = $this->boundedSources($brief, $search);
        $prompt = app(GroundedCandidateSynthesisPrompt::class)->messages($brief, $search->queries, $bounded);
        $decoded = app(OpenAiCompatibleCatalogCandidateSynthesisClient::class)
            ->complete($prompt['system'], $prompt['user'], $prompt['schema']);

        $candidates = array_slice($decoded['candidates'], 0, $brief->maxCandidates);
        $hydrated = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || array_is_list($candidate)) {
                throw new CatalogCandidateSynthesisException('The catalog candidate synthesis response was malformed.');
            }

            $hydrated[] = $this->hydrateCandidate($candidate, $search->corpus);
        }

        return new CatalogCandidateDiscoveryResult(
            candidates: $hydrated,
            corpus: $search->corpus,
            queries: $search->queries,
            metadata: array_merge($search->metadata, [
                'provider' => 'web_research',
                'synthesis' => 'completed',
                'sources_sent' => count($bounded),
            ]),
        );
    }

    /**
     * @return list<RetrievedCatalogCandidateSource>
     */
    private function boundedSources(CatalogCandidateResearchBrief $brief, CatalogCandidateSearchResult $search): array
    {
        $maxSources = max(1, (int) config('catalog_candidate_discovery.synthesis.max_sources', 20));
        $snippetMax = max(1, (int) config('catalog_candidate_discovery.search.snippet_max_length', 400));
        $maxPromptChars = max(1, (int) config('catalog_candidate_discovery.synthesis.max_prompt_chars', 24000));
        $prompt = app(GroundedCandidateSynthesisPrompt::class);

        $sources = [];

        foreach (array_slice($search->corpus, 0, $maxSources) as $source) {
            $snippet = $source->snippet;

            if (mb_strlen($snippet) > $snippetMax) {
                $snippet = mb_substr($snippet, 0, $snippetMax);
            }

            $sources[] = new RetrievedCatalogCandidateSource(
                url: $source->url,
                title: $source->title,
                snippet: $snippet,
                sourceName: $source->sourceName,
                retrievedAt: $source->retrievedAt,
            );
        }

        while ($sources !== []) {
            $messages = $prompt->messages($brief, $search->queries, $sources);
            $size = mb_strlen($messages['system']) + mb_strlen($messages['user']);

            if ($size <= $maxPromptChars) {
                break;
            }

            array_pop($sources);
        }

        if ($sources === []) {
            throw new CatalogCandidateSynthesisException('The synthesis prompt exceeds the configured size limit.');
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<RetrievedCatalogCandidateSource>  $corpus
     * @return array<string, mixed>
     */
    private function hydrateCandidate(array $candidate, array $corpus): array
    {
        $evidence = $candidate['evidence'] ?? [];
        $hydratedEvidence = [];

        if (is_array($evidence) && array_is_list($evidence)) {
            foreach ($evidence as $item) {
                if (! is_array($item) || array_is_list($item)) {
                    continue;
                }

                $url = is_string($item['source_url'] ?? null) ? trim($item['source_url']) : '';
                $canonical = $url !== '' ? CatalogCandidateSourceUrl::resolveAgainstCorpus($url, $corpus) : null;
                $resolvedUrl = $canonical ?? ($url !== '' ? $url : null);
                $source = $resolvedUrl !== null ? CatalogCandidateSourceUrl::findInCorpus($resolvedUrl, $corpus) : null;

                $hydratedEvidence[] = [
                    'source_type' => CatalogCandidateSourceType::Web->value,
                    'source_name' => $source?->sourceName,
                    'source_url' => $resolvedUrl,
                    'summary' => is_string($item['summary'] ?? null) ? trim($item['summary']) : null,
                    'observed_at' => $source?->retrievedAt,
                ];
            }
        }

        return [
            'title' => is_string($candidate['title'] ?? null) ? trim($candidate['title']) : '',
            'summary' => is_string($candidate['summary'] ?? null) ? trim($candidate['summary']) : null,
            'source_type' => CatalogCandidateSourceType::AiResearch->value,
            'source_name' => 'Gift Candidate Research',
            'source_url' => null,
            'external_reference' => null,
            'priority' => CatalogCandidatePriority::Normal->value,
            'evidence' => $hydratedEvidence,
        ];
    }
}
