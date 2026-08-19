<?php

namespace App\Support;

use App\CatalogCoverage\CatalogCoverageReport;
use App\Enums\CoverageGapSeverity;
use Illuminate\Console\Command;

final class CatalogCoverageReporter
{
    public static function printFooter(Command $command, CatalogCoverageReport $report): void
    {
        $command->newLine();
        $command->line('Catalog coverage');

        $command->line(sprintf(
            '  Products: %d total, %d published, %d automation drafts',
            $report->totalProducts,
            $report->publishedProducts,
            $report->automationDraftProducts,
        ));

        if ($report->unpricedCount > 0) {
            $command->line("  Unpriced: {$report->unpricedCount}");
        }

        $command->newLine();
        $command->line('  Budget');

        foreach ($report->budgetCoverage as $row) {
            if ($row->severity === CoverageGapSeverity::Healthy) {
                continue;
            }

            $command->line(sprintf(
                '    %-16s %4d   %s',
                $row->name,
                $row->productCount,
                strtoupper($row->severity->value),
            ));
        }

        $topGaps = array_filter(
            $report->topGaps(10),
            fn ($gap) => $gap->severity !== CoverageGapSeverity::Healthy,
        );

        if ($topGaps !== []) {
            $command->newLine();
            $command->line('  Top gaps');

            foreach ($topGaps as $gap) {
                $command->line(sprintf(
                    '    %-40s %d',
                    $gap->label,
                    $gap->productCount,
                ));
            }
        }
    }
}
