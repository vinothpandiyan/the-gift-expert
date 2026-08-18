<?php

namespace App\Console\Commands;

use App\Actions\CatalogCandidate\DiscoverCatalogCandidatesAction;
use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionResult;
use App\Enums\CatalogCandidateIngestionItemStatus;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class DiscoverCatalogCandidatesCommand extends Command
{
    protected $signature = 'catalog-candidates:discover {brief : Research brief for gift candidate ideas} {--market=IN} {--max=10} {--freshness-days=30} {--dry-run : Research and validate without writing}';

    protected $description = 'Discover catalog candidate gift ideas without creating gifts.';

    public function handle(DiscoverCatalogCandidatesAction $discover): int
    {
        try {
            $brief = CatalogCandidateResearchBrief::from(
                $this->argument('brief'),
                $this->option('market'),
                $this->option('max'),
                $this->option('freshness-days'),
            );

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

    private function printResult(CatalogCandidateIngestionResult $result): void
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run completed. No catalog candidates were written.');
        } elseif ($result->run !== null) {
            $this->info("Ingestion run {$result->run->id} is {$result->run->status->value}.");
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
        }
    }
}
