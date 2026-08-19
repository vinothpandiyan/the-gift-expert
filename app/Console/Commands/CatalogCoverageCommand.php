<?php

namespace App\Console\Commands;

use App\Actions\Catalog\AnalyzeCatalogCoverageAction;
use App\CatalogCoverage\CatalogCoverageOptions;
use App\CatalogCoverage\CatalogCoverageReport;
use App\Enums\CoverageGapSeverity;
use Illuminate\Console\Command;

class CatalogCoverageCommand extends Command
{
    protected $signature = 'catalog:coverage
        {--published-only : Only include published products}
        {--include-manual-drafts : Include draft products without automation lineage}
        {--dimension= : Filter dimension coverage output (relationship|occasion|category|recipient_type|interest|profession|gift_type)}
        {--show-gaps : Print the full gap list instead of only top gaps}
        {--json : Output machine-readable JSON}';

    protected $description = 'Analyze read-only catalog coverage across budget and taxonomy dimensions.';

    public function handle(AnalyzeCatalogCoverageAction $analyze): int
    {
        $options = CatalogCoverageOptions::from([
            'published_only' => (bool) $this->option('published-only'),
            'include_manual_drafts' => (bool) $this->option('include-manual-drafts'),
            'dimension' => $this->option('dimension'),
        ]);

        $report = $analyze->execute($options);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->printHumanReport($report, (bool) $this->option('show-gaps'), $options->dimensionFilter);

        return self::SUCCESS;
    }

    private function printHumanReport(CatalogCoverageReport $report, bool $showAllGaps, ?string $dimensionFilter): void
    {
        $this->info('Catalog Coverage');
        $this->newLine();

        $this->line('Products');
        $this->line("  Total: {$report->totalProducts}");
        $this->line("  Published: {$report->publishedProducts}");
        $this->line("  Automation drafts: {$report->automationDraftProducts}");

        if ($report->unpricedCount > 0) {
            $this->line("  Unpriced: {$report->unpricedCount}");
        }

        $this->newLine();
        $this->line('Budget');

        foreach ($report->budgetCoverage as $row) {
            $this->line(sprintf(
                '  %-16s %4d   %s',
                $row->name,
                $row->productCount,
                strtoupper($row->severity->value),
            ));
        }

        if ($dimensionFilter === null) {
            $this->newLine();
            $this->line('Missing taxonomy');
            $this->line("  No primary category: {$report->missingTaxonomy->noPrimaryCategory}");
            $this->line("  Uncategorized: {$report->missingTaxonomy->noCategory}");
            $this->line("  No relationship: {$report->missingTaxonomy->noRelationship}");
            $this->line("  No occasion: {$report->missingTaxonomy->noOccasion}");
            $this->line("  No recipient type: {$report->missingTaxonomy->noRecipientType}");
            $this->line("  No interest: {$report->missingTaxonomy->noInterest}");
            $this->line("  No profession: {$report->missingTaxonomy->noProfession}");
            $this->line("  No gift type: {$report->missingTaxonomy->noGiftType}");
        }

        if ($report->automationDraftProducts > 0) {
            $this->newLine();
            $this->line('Automation draft readiness');
            $this->line("  Ready: {$report->readiness->ready}");
            $this->line("  Needs review: {$report->readiness->needsReview}");
            $this->line("  Blocked: {$report->readiness->blocked}");
            $this->line("  Unevaluated: {$report->readiness->unevaluated}");
        }

        $gaps = $showAllGaps ? $report->gaps : $report->topGaps();

        if ($gaps !== []) {
            $this->newLine();
            $this->line($showAllGaps ? 'Gaps' : 'Top gaps');

            foreach ($gaps as $gap) {
                if ($gap->severity === CoverageGapSeverity::Healthy) {
                    continue;
                }

                $this->line(sprintf(
                    '  %-40s %d',
                    $gap->label,
                    $gap->productCount,
                ));
            }
        }
    }
}
