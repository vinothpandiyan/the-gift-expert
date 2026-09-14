<?php

namespace App\Console\Commands;

use App\Actions\CatalogExpansion\BuildIncomingCatalogExpansionReportAction;
use Illuminate\Console\Command;

class IncomingCatalogExpansionReportCommand extends Command
{
    protected $signature = 'catalog:incoming-expansion-report
        {--product=* : Incoming Product IDs to evaluate}
        {--json : Output machine-readable JSON}';

    protected $description = 'Advisory gap-contribution and replacement report for an incoming Product cohort. Does not publish or archive.';

    public function handle(BuildIncomingCatalogExpansionReportAction $buildReport): int
    {
        $productIds = array_values(array_filter(
            array_map(intval(...), (array) $this->option('product')),
            fn (int $id): bool => $id > 0,
        ));

        if ($productIds === []) {
            $this->error('Provide at least one --product ID. This command does not evaluate the full catalog.');

            return self::FAILURE;
        }

        $report = $buildReport->execute($productIds);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Incoming catalog expansion report (advisory only)');
        $this->line('Products: '.$report['product_count']);
        $this->line('Father fit: '.($report['father_fit'] ? 'yes' : 'no'));
        $this->line('Gift card / digital fit: '.($report['gift_card_fit'] ? 'yes' : 'no'));
        $this->line('Experience gift fit: '.($report['experience_gift_fit'] ? 'yes' : 'no'));
        $this->comment('No Product was published or archived by this report.');

        return self::SUCCESS;
    }
}
