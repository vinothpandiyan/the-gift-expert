<?php

namespace App\CommercialSourcing;

use App\Models\CatalogCandidate;
use App\Support\CatalogCandidateSourceUrl;
use InvalidArgumentException;

class FakeCommercialOfferSearchProvider implements CommercialOfferSearchProvider
{
    public function search(CatalogCandidate $candidate, string $market): CommercialOfferSearchResult
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new InvalidArgumentException('The fake commercial search provider is not permitted in this environment.');
        }

        $path = config('commercial_sourcing.search.providers.fake.fixture');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new InvalidArgumentException('The fake commercial search fixture is not configured.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['hits']) || ! is_array($decoded['hits'])) {
            throw new InvalidArgumentException('The fake commercial search fixture is malformed.');
        }

        $hits = [];
        $queries = app(CommercialOfferSearchQueryBuilder::class)->queries($candidate, $market);

        foreach ($decoded['hits'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = CatalogCandidateSourceUrl::normalize($item['url'] ?? null);

            if ($url === null) {
                continue;
            }

            $hits[] = new CommercialSearchHit(
                url: $url,
                title: trim((string) ($item['title'] ?? 'Offer')),
                snippet: trim((string) ($item['snippet'] ?? '')),
                imageUrls: [],
                retrievedAt: now(),
            );
        }

        return new CommercialOfferSearchResult(
            hits: $hits,
            queries: $queries,
            metadata: [
                'provider' => 'fake',
                'market' => strtoupper(trim($market)),
                'include_domains' => app(CommercialSourcingMerchants::class)->includeDomains($market),
            ],
        );
    }
}
