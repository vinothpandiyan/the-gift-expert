<?php

namespace App\Actions\CatalogCandidate;

use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Discovery\CatalogCandidateSearchProvider;
use App\CatalogCandidate\Discovery\CatalogCandidateSearchResult;
use InvalidArgumentException;

class SearchCatalogCandidateSourcesAction
{
    public function execute(CatalogCandidateResearchBrief $brief): CatalogCandidateSearchResult
    {
        return $this->resolveProvider()->search($brief);
    }

    public function resolveProvider(?string $providerKey = null): CatalogCandidateSearchProvider
    {
        $providerKey ??= $this->providerKey();
        $config = config('catalog_candidate_discovery.search.providers.'.$providerKey);

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown catalog candidate search provider [{$providerKey}].");
        }

        $allowedEnvironments = $config['allowed_environments'] ?? null;

        if (is_array($allowedEnvironments) && $allowedEnvironments !== [] && ! app()->environment($allowedEnvironments)) {
            throw new InvalidArgumentException(
                "The [{$providerKey}] catalog candidate search provider is not permitted in this environment.",
            );
        }

        $class = $config['class'] ?? null;

        if (! is_string($class) || $class === '' || ! is_a($class, CatalogCandidateSearchProvider::class, true)) {
            throw new InvalidArgumentException("Catalog candidate search provider [{$providerKey}] is not configured.");
        }

        $provider = app($class);

        if (! $provider instanceof CatalogCandidateSearchProvider) {
            throw new InvalidArgumentException("Catalog candidate search provider [{$providerKey}] is not configured.");
        }

        return $provider;
    }

    public function providerKey(): string
    {
        $providerKey = config('catalog_candidate_discovery.search.provider');

        if (! is_string($providerKey) || trim($providerKey) === '') {
            throw new InvalidArgumentException('A catalog candidate search provider is not configured.');
        }

        return trim($providerKey);
    }
}
