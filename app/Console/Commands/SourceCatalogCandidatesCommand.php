<?php

namespace App\Console\Commands;

use App\Actions\CatalogCandidate\SourceCatalogCandidatesAction;
use App\CommercialSourcing\CatalogCandidateSourcingResult;
use App\Enums\CatalogCandidateSourcingItemStatus;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class SourceCatalogCandidatesCommand extends Command
{
    protected $signature = 'catalog-candidates:source
        {--candidate= : Source a single catalog candidate ID}
        {--market=IN}
        {--limit=20}
        {--include-discovered : Also source discovered candidates}
        {--enrich : Enrich selected offers with copy, taxonomy, affiliate URL, and budget mapping}
        {--enrich-item= : Re-enrich an existing sourcing item ID without searching}
        {--dry-run : Search and rank without writing sourcing runs}';

    protected $description = 'Source commercial offers for catalog candidates without creating gifts.';

    public function handle(SourceCatalogCandidatesAction $source): int
    {
        try {
            $result = $source->execute(
                market: (string) $this->option('market'),
                candidateId: $this->candidateId(),
                limit: (int) $this->option('limit'),
                includeDiscovered: (bool) $this->option('include-discovered'),
                dryRun: (bool) $this->option('dry-run'),
                enrich: (bool) $this->option('enrich') || $this->option('enrich-item') !== null && $this->option('enrich-item') !== '',
                enrichItemId: $this->enrichItemId(),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->printResult($result);

        return self::SUCCESS;
    }

    private function candidateId(): ?int
    {
        $value = $this->option('candidate');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException('The --candidate option must be a positive catalog candidate ID.');
        }

        return (int) $value;
    }

    private function enrichItemId(): ?int
    {
        $value = $this->option('enrich-item');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException('The --enrich-item option must be a positive sourcing item ID.');
        }

        return (int) $value;
    }

    private function printResult(CatalogCandidateSourcingResult $result): void
    {
        if ($result->dryRun) {
            $this->info('Dry run completed. No sourcing runs or catalog items were written.');
        } elseif ($result->run !== null) {
            $this->info("Sourcing run {$result->run->id} is {$result->run->status->value}.");
        }

        $this->line('Market: '.$result->market);
        $this->line("Total: {$result->itemsTotal}");
        $this->line("Succeeded: {$result->itemsSucceeded}");
        $this->line("Skipped: {$result->itemsSkipped}");
        $this->line("Failed: {$result->itemsFailed}");

        foreach ($result->outcomes as $outcome) {
            $this->newLine();
            $this->line("- [{$outcome->candidateId}] {$outcome->candidateTitle}: {$outcome->status->value}");

            if ($outcome->selected !== null) {
                $this->line('  merchant: '.$outcome->selected->merchantSlug);
                $this->line('  url: '.$outcome->selected->sourceUrl);
                $this->line('  external_id: '.($outcome->selected->externalProductId ?? '(none)'));
                $this->line('  external_id_source: '.$outcome->selected->externalIdSource->value);

                if ($outcome->selected->priceAmount !== null) {
                    $this->line('  price: '.$outcome->selected->priceAmount.' '.($outcome->selected->priceCurrency ?? ''));
                } else {
                    $this->line('  price: (none)');
                }
            }

            if ($outcome->payload !== null) {
                $this->line('  affiliate_ready: '.($outcome->payload->affiliateReady ? 'yes' : 'no'));
                $this->line('  primary_category_id: '.($outcome->payload->primaryCategoryId ?? '(none)'));
                $this->line('  budget_range_id: '.($outcome->payload->budgetRangeId ?? '(none)'));
            }

            if ($outcome->exceptionCodes !== []) {
                $this->line('  exceptions: '.implode(', ', $outcome->exceptionCodes));
            }

            if ($outcome->error !== null && $outcome->status !== CatalogCandidateSourcingItemStatus::Succeeded) {
                $this->line('  '.$outcome->error);
            }
        }
    }
}
