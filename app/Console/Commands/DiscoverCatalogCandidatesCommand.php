<?php

namespace App\Console\Commands;

use App\Actions\CatalogCandidate\DiscoverCatalogCandidatesAction;
use App\Actions\CatalogCandidate\SearchCatalogCandidateSourcesAction;
use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Discovery\CatalogCandidateSearchResult;
use App\CatalogCandidate\Discovery\RetrievedCatalogCandidateSource;
use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionResult;
use App\Enums\CatalogCandidateIngestionItemStatus;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class DiscoverCatalogCandidatesCommand extends Command
{
    protected $signature = 'catalog-candidates:discover {brief : Research brief for gift candidate ideas} {--market=IN} {--max=10} {--freshness-days=30} {--dry-run : Research and validate without writing} {--search-only : Retrieve search corpus without creating candidates}';

    protected $description = 'Discover catalog candidate gift ideas without creating gifts.';

    public function handle(
        DiscoverCatalogCandidatesAction $discover,
        SearchCatalogCandidateSourcesAction $search,
    ): int {
        try {
            $brief = CatalogCandidateResearchBrief::from(
                $this->argument('brief'),
                $this->option('market'),
                $this->option('max'),
                $this->option('freshness-days'),
            );

            if ($this->option('search-only')) {
                $this->printSearchResult($search->execute($brief));

                return self::SUCCESS;
            }

            $result = $discover->execute($brief, (bool) $this->option('dry-run'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->printResult($result);

        if ($result->itemsFailed > 0) {
            $this->warn('Some items failed. The batch was not aborted.');
        }

        return self::SUCCESS;
    }

    private function printSearchResult(CatalogCandidateSearchResult $result): void
    {
        $this->warn('SEARCH-ONLY');
        $this->info('No catalog candidates, evidence, or ingestion runs will be written.');

        $this->newLine();
        $this->line('Queries:');

        foreach (array_values($result->queries) as $index => $query) {
            $this->line(($index + 1).'. '.$query);
        }

        if ($result->queries === []) {
            $this->line('(none)');
        }

        $invalid = $result->metadata['invalid_result_count'] ?? 0;

        $this->newLine();
        $this->line('Sources: '.count($result->corpus));

        if (is_numeric($invalid) && (int) $invalid > 0) {
            $this->line('Skipped invalid results: '.(int) $invalid);
        }

        if ($result->corpus === []) {
            $this->newLine();
            $this->info('No useful search sources returned.');

            return;
        }

        $this->newLine();

        foreach ($result->corpus as $source) {
            $this->printSource($source);
        }
    }

    private function printSource(RetrievedCatalogCandidateSource $source): void
    {
        $this->line('- '.$source->title);
        $this->line('  domain: '.$source->sourceName);
        $this->line('  url: '.$source->url);
        $this->line('  snippet: '.$this->compactSnippet($source->snippet));
    }

    private function compactSnippet(string $snippet): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($snippet));
        $snippet = is_string($collapsed) ? $collapsed : trim($snippet);

        if (mb_strlen($snippet) <= 160) {
            return $snippet;
        }

        return rtrim(mb_substr($snippet, 0, 160)).'…';
    }

    private function printResult(CatalogCandidateIngestionResult $result): void
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run completed. No catalog candidates were written.');
        } elseif ($result->run !== null) {
            $this->info("Ingestion run {$result->run->id} is {$result->run->status->value}.");
        }

        if ($result->queries !== []) {
            $this->newLine();
            $this->line('Queries:');

            foreach (array_values($result->queries) as $index => $query) {
                $this->line(($index + 1).'. '.$query);
            }
        }

        $this->line("Total: {$result->itemsTotal}");
        $this->line("Succeeded: {$result->itemsSucceeded}");
        $this->line("Skipped: {$result->itemsSkipped}");
        $this->line("Failed: {$result->itemsFailed}");

        foreach ($result->outcomes as $outcome) {
            $title = $outcome->title ?? '(untitled)';
            $detail = $outcome->error ?? $outcome->status->value;

            if ($outcome->status === CatalogCandidateIngestionItemStatus::Succeeded && $outcome->error === null) {
                $detail = $outcome->candidateId !== null
                    ? "succeeded — candidate {$outcome->candidateId}"
                    : 'succeeded';
            }

            $this->line("- [{$outcome->index}] {$title}: {$detail}");

            foreach ($outcome->evidenceUrls as $url) {
                $this->line('    evidence: '.$url);
            }
        }
    }
}
