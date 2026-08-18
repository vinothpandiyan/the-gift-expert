<?php

namespace App\Actions\CatalogCandidate;

use App\CatalogCandidate\Discovery\CatalogCandidateDiscoveryProvider;
use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionResult;
use App\Enums\CatalogCandidateIngestionFormat;
use InvalidArgumentException;

class DiscoverCatalogCandidatesAction
{
    public function execute(
        CatalogCandidateResearchBrief $brief,
        bool $dryRun = false,
        ?int $createdByUserId = null,
    ): CatalogCandidateIngestionResult {
        $providerKey = $this->providerKey();
        $provider = $this->resolveProvider($providerKey);
        $discovered = $provider->discover($brief);
        $rows = app(GroundDiscoveredCandidatesAction::class)->execute($discovered);

        return app(IngestCatalogCandidatesAction::class)->execute(
            $rows,
            CatalogCandidateIngestionFormat::Discovery,
            'discovery:'.$providerKey,
            $dryRun,
            $createdByUserId,
        );
    }

    public function resolveProvider(?string $providerKey = null): CatalogCandidateDiscoveryProvider
    {
        $providerKey ??= $this->providerKey();
        $config = config('catalog_candidate_discovery.providers.'.$providerKey);

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown catalog candidate discovery provider [{$providerKey}].");
        }

        $allowedEnvironments = $config['allowed_environments'] ?? null;

        if (is_array($allowedEnvironments) && $allowedEnvironments !== [] && ! app()->environment($allowedEnvironments)) {
            throw new InvalidArgumentException(
                "The [{$providerKey}] catalog candidate discovery provider is not permitted in this environment.",
            );
        }

        $class = $config['class'] ?? null;

        if (! is_string($class) || $class === '' || ! is_a($class, CatalogCandidateDiscoveryProvider::class, true)) {
            throw new InvalidArgumentException("Catalog candidate discovery provider [{$providerKey}] is not configured.");
        }

        $provider = app($class);

        if (! $provider instanceof CatalogCandidateDiscoveryProvider) {
            throw new InvalidArgumentException("Catalog candidate discovery provider [{$providerKey}] is not configured.");
        }

        return $provider;
    }

    public function providerKey(): string
    {
        $providerKey = config('catalog_candidate_discovery.provider');

        if (! is_string($providerKey) || trim($providerKey) === '') {
            throw new InvalidArgumentException('A catalog candidate discovery provider is not configured.');
        }

        return trim($providerKey);
    }
}
