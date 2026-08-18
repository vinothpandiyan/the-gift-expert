<?php

namespace App\Actions\CatalogCandidate;

use App\CatalogCandidate\Discovery\CatalogCandidateDiscoveryProvider;
use App\CatalogCandidate\Discovery\CatalogCandidateDiscoveryResult;
use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionResult;
use App\Enums\CatalogCandidateDiscoveryRunStatus;
use App\Enums\CatalogCandidateIngestionFormat;
use App\Models\CatalogCandidateDiscoveryRun;
use InvalidArgumentException;
use Throwable;

class DiscoverCatalogCandidatesAction
{
    public function execute(
        CatalogCandidateResearchBrief $brief,
        bool $dryRun = false,
        ?int $createdByUserId = null,
    ): CatalogCandidateIngestionResult {
        $providerKey = $this->providerKey();
        $provider = $this->resolveProvider($providerKey);
        $run = $dryRun ? null : $this->startRun($providerKey, $brief);

        try {
            $discovered = $provider->discover($brief);
        } catch (Throwable $exception) {
            $this->failRun($run, $exception->getMessage());

            throw $exception;
        }

        $this->recordProviderResult($run, $discovered);

        if ($discovered->candidates === []) {
            $this->completeRun($run, CatalogCandidateDiscoveryRunStatus::Completed);

            return $this->emptyResult($discovered->queries);
        }

        try {
            $rows = app(GroundDiscoveredCandidatesAction::class)->execute($discovered);
            $ingestion = app(IngestCatalogCandidatesAction::class)->execute(
                $rows,
                CatalogCandidateIngestionFormat::Discovery,
                'discovery:'.$providerKey,
                $dryRun,
                $createdByUserId,
            );
        } catch (Throwable $exception) {
            $this->failRun($run, $exception->getMessage());

            throw $exception;
        }

        $this->finishRun($run, $ingestion);

        return $this->withQueries($ingestion, $discovered->queries);
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

    private function startRun(string $providerKey, CatalogCandidateResearchBrief $brief): CatalogCandidateDiscoveryRun
    {
        return CatalogCandidateDiscoveryRun::query()->create([
            'provider_key' => $providerKey,
            'brief' => $brief->brief,
            'market' => $brief->market,
            'max_candidates' => $brief->maxCandidates,
            'freshness_days' => $brief->freshnessDays,
            'status' => CatalogCandidateDiscoveryRunStatus::Running,
            'candidates_proposed' => 0,
            'started_at' => now(),
        ]);
    }

    private function recordProviderResult(?CatalogCandidateDiscoveryRun $run, CatalogCandidateDiscoveryResult $discovered): void
    {
        if ($run === null) {
            return;
        }

        $run->queries = $discovered->queries;
        $run->retrieved_urls = array_values(array_map(
            fn ($source): string => $source->url,
            $discovered->corpus,
        ));
        $run->candidates_proposed = count($discovered->candidates);
        $run->save();
    }

    private function completeRun(?CatalogCandidateDiscoveryRun $run, CatalogCandidateDiscoveryRunStatus $status): void
    {
        if ($run === null) {
            return;
        }

        $run->status = $status;
        $run->finished_at = now();
        $run->save();
    }

    private function failRun(?CatalogCandidateDiscoveryRun $run, string $error): void
    {
        if ($run === null) {
            return;
        }

        $run->status = CatalogCandidateDiscoveryRunStatus::Failed;
        $run->error = $error;
        $run->finished_at = now();
        $run->save();
    }

    private function finishRun(?CatalogCandidateDiscoveryRun $run, CatalogCandidateIngestionResult $ingestion): void
    {
        if ($run === null) {
            return;
        }

        $run->catalog_candidate_ingestion_run_id = $ingestion->run?->id;
        $run->status = $ingestion->itemsFailed > 0
            ? CatalogCandidateDiscoveryRunStatus::CompletedWithErrors
            : CatalogCandidateDiscoveryRunStatus::Completed;
        $run->finished_at = now();
        $run->save();
    }

    /**
     * @param  list<string>  $queries
     */
    private function emptyResult(array $queries): CatalogCandidateIngestionResult
    {
        return new CatalogCandidateIngestionResult(
            itemsTotal: 0,
            itemsSucceeded: 0,
            itemsSkipped: 0,
            itemsFailed: 0,
            outcomes: [],
            queries: $queries,
        );
    }

    /**
     * @param  list<string>  $queries
     */
    private function withQueries(CatalogCandidateIngestionResult $ingestion, array $queries): CatalogCandidateIngestionResult
    {
        return new CatalogCandidateIngestionResult(
            itemsTotal: $ingestion->itemsTotal,
            itemsSucceeded: $ingestion->itemsSucceeded,
            itemsSkipped: $ingestion->itemsSkipped,
            itemsFailed: $ingestion->itemsFailed,
            outcomes: $ingestion->outcomes,
            run: $ingestion->run,
            queries: $queries,
        );
    }
}
