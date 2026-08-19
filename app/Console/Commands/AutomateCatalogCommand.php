<?php

namespace App\Console\Commands;

use App\Actions\Catalog\AnalyzeCatalogCoverageAction;
use App\Actions\CatalogCandidate\AutomateCatalogAction;
use App\CatalogAutomation\CatalogAutomationOptions;
use App\CatalogAutomation\CatalogAutomationResult;
use App\CatalogCoverage\CatalogCoverageOptions;
use App\Support\CatalogCoverageReporter;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class AutomateCatalogCommand extends Command
{
    protected $signature = 'catalog:automate
        {brief : Research brief for gift candidate ideas}
        {--market=IN}
        {--max=10}
        {--freshness-days=30}
        {--candidate-limit= : Maximum candidates to pass into sourcing}
        {--dry-run : Research and simulate without writing}
        {--stop-after=readiness : discovery|sourcing|enrichment|promotion|readiness}
        {--no-enrich-existing : Reuse persisted enrichment snapshots via promotion instead of re-sourcing}
        {--report-coverage : Print a catalog coverage summary footer after automation}';

    protected $description = 'Run end-to-end catalog automation from discovery through readiness without publishing.';

    public function handle(AutomateCatalogAction $automate, AnalyzeCatalogCoverageAction $analyzeCoverage): int
    {
        try {
            $options = CatalogAutomationOptions::from([
                'brief' => $this->argument('brief'),
                'market' => $this->option('market'),
                'max' => $this->option('max'),
                'freshness_days' => $this->option('freshness-days'),
                'candidate_limit' => $this->option('candidate-limit'),
                'dry_run' => (bool) $this->option('dry-run'),
                'stop_after' => $this->option('stop-after'),
                'no_enrich_existing' => (bool) $this->option('no-enrich-existing'),
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $result = $automate->execute($options);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->printResult($result);

        if ((bool) $this->option('report-coverage')) {
            $coverage = $analyzeCoverage->execute(new CatalogCoverageOptions);
            CatalogCoverageReporter::printFooter($this, $coverage);
        }

        return self::SUCCESS;
    }

    private function printResult(CatalogAutomationResult $result): void
    {
        if ($result->dryRun) {
            $this->info('Dry run completed. No automation or catalog rows were written.');
        } elseif ($result->automationRunId !== null) {
            $this->info("Automation Run #{$result->automationRunId}");
        }

        $this->newLine();
        $this->line('Discovery');
        $this->line("  Queries: {$result->queriesCount}");
        $this->line("  Proposed: {$result->candidatesProposed}");
        $this->line("  Added: {$result->candidatesAdded}");
        $this->line("  Duplicate: {$result->candidatesDuplicate}");

        if ($result->existingCandidatesContinued > 0) {
            $this->line("  Existing continued: {$result->existingCandidatesContinued}");
        }

        if ($result->downstreamSkipped && $result->downstreamSkippedReason !== null) {
            $this->newLine();
            $this->warn($result->downstreamSkippedReason);

            return;
        }

        if ($result->stoppedAfter->value === 'discovery') {
            return;
        }

        $this->newLine();
        $this->line('Commercial sourcing');

        if ($result->alreadyPromotedSkipped > 0) {
            $this->line("  Already promoted skipped: {$result->alreadyPromotedSkipped}");
        }

        $this->line("  Offers selected: {$result->candidatesSourced}");

        if ($result->sourcingRunId !== null || $result->candidatesSourced > 0) {
            $this->line('  Sourcing run: '.($result->sourcingRunId ?? '(dry run)'));
        }

        if ($result->stoppedAfter->value === 'sourcing') {
            return;
        }

        $this->line("  Enriched: {$result->candidatesEnriched}");

        if ($result->stoppedAfter->value === 'enrichment') {
            return;
        }

        $this->newLine();
        $this->line('Promotion');
        $this->line("  Promoted: {$result->candidatesPromoted}");

        if ($result->stoppedAfter->value === 'promotion') {
            return;
        }

        $this->newLine();
        $this->line('Readiness');
        $this->line("  Ready: {$result->ready}");
        $this->line("  Needs review: {$result->needsReview}");
        $this->line("  Blocked: {$result->blocked}");

        $this->newLine();
        $this->line('Next:');
        $this->line('  Catalog Candidates → Needs Review');
        $this->line('  Gifts → Ready for Publish');
    }
}
