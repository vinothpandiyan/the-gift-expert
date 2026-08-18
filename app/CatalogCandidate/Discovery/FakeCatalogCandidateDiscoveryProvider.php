<?php

namespace App\CatalogCandidate\Discovery;

use RuntimeException;

class FakeCatalogCandidateDiscoveryProvider implements CatalogCandidateDiscoveryProvider
{
    public function discover(CatalogCandidateResearchBrief $brief): CatalogCandidateDiscoveryResult
    {
        $path = (string) config('catalog_candidate_discovery.providers.fake.fixture', '');

        if ($path === '' || ! is_readable($path)) {
            throw new RuntimeException('The fake catalog candidate discovery fixture could not be read.');
        }

        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            throw new RuntimeException('The fake catalog candidate discovery fixture could not be read.');
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The fake catalog candidate discovery fixture is malformed.');
        }

        $candidates = $decoded['candidates'] ?? null;
        $corpus = $decoded['corpus'] ?? null;
        $queries = $decoded['queries'] ?? [];

        if (! is_array($candidates) || ! array_is_list($candidates)) {
            throw new RuntimeException('The fake catalog candidate discovery fixture is malformed.');
        }

        if (! is_array($corpus) || ! array_is_list($corpus)) {
            throw new RuntimeException('The fake catalog candidate discovery fixture is malformed.');
        }

        if (! is_array($queries) || ! array_is_list($queries)) {
            throw new RuntimeException('The fake catalog candidate discovery fixture is malformed.');
        }

        $retrieved = [];

        foreach ($corpus as $source) {
            if (! is_array($source)) {
                throw new RuntimeException('The fake catalog candidate discovery fixture is malformed.');
            }

            $url = trim((string) ($source['url'] ?? ''));
            $title = trim((string) ($source['title'] ?? ''));
            $snippet = trim((string) ($source['snippet'] ?? ''));
            $sourceName = trim((string) ($source['source_name'] ?? ''));

            if ($url === '' || $title === '' || $snippet === '' || $sourceName === '') {
                throw new RuntimeException('The fake catalog candidate discovery fixture is malformed.');
            }

            $retrieved[] = new RetrievedCatalogCandidateSource(
                url: $url,
                title: $title,
                snippet: $snippet,
                sourceName: $sourceName,
                retrievedAt: $source['retrieved_at'] ?? null,
            );
        }

        $normalizedQueries = [];

        foreach ($queries as $query) {
            if (! is_string($query) || trim($query) === '') {
                throw new RuntimeException('The fake catalog candidate discovery fixture is malformed.');
            }

            $normalizedQueries[] = trim($query);
        }

        $sliced = array_slice($candidates, 0, $brief->maxCandidates);

        foreach ($sliced as $candidate) {
            if (! is_array($candidate) || array_is_list($candidate)) {
                throw new RuntimeException('The fake catalog candidate discovery fixture is malformed.');
            }
        }

        return new CatalogCandidateDiscoveryResult(
            candidates: array_values($sliced),
            corpus: $retrieved,
            queries: $normalizedQueries,
            metadata: [
                'provider' => 'fake',
                'brief' => $brief->brief,
                'market' => $brief->market,
            ],
        );
    }
}
