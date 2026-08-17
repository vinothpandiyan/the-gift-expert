<?php

namespace App\Console\Commands;

use App\Actions\Import\ImportCatalogAction;
use App\Import\ImportedCatalogItem;
use App\Models\Merchant;
use Illuminate\Console\Command;
use Throwable;

class ImportCatalogCommand extends Command
{
    protected $signature = 'catalog:import {merchant : The merchant slug} {--dry-run : Resolve provider and list items without writing or dispatching}';

    protected $description = 'Import a merchant catalog into draft gifts without publishing.';

    public function handle(ImportCatalogAction $importCatalog): int
    {
        $slug = (string) $this->argument('merchant');
        $merchant = Merchant::query()->where('slug', $slug)->first();

        if ($merchant === null) {
            $this->error("Merchant [{$slug}] was not found.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($importCatalog, $merchant);
        }

        try {
            $run = $importCatalog->execute($merchant);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Import run {$run->id} is {$run->status->value}.");
        $this->line("Total: {$run->items_total}");
        $this->line("Succeeded: {$run->items_succeeded}");
        $this->line("Failed: {$run->items_failed}");
        $this->line("Skipped: {$run->items_skipped}");

        return self::SUCCESS;
    }

    private function dryRun(ImportCatalogAction $importCatalog, Merchant $merchant): int
    {
        try {
            $provider = $importCatalog->resolveProvider($merchant);
            $items = iterator_to_array($provider->eachProduct($merchant), false);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $valid = 0;
        $skipped = 0;
        $seenExternalIds = [];
        $reasons = [];

        foreach ($items as $item) {
            if (! $item instanceof ImportedCatalogItem || blank($item->external_product_id)) {
                $skipped++;
                $reasons[] = 'missing external product ID';

                continue;
            }

            if (isset($seenExternalIds[$item->external_product_id])) {
                $skipped++;
                $reasons[] = $item->external_product_id.': duplicate external product ID in this catalog snapshot.';

                continue;
            }

            $seenExternalIds[$item->external_product_id] = true;

            if (blank($item->name)) {
                $skipped++;
                $reasons[] = $item->external_product_id.': A gift name is required.';

                continue;
            }

            if (blank($item->affiliate_url)) {
                $skipped++;
                $reasons[] = $item->external_product_id.': An affiliate URL is required.';

                continue;
            }

            $valid++;
        }

        $this->info("Dry run for merchant [{$merchant->slug}] using provider [{$merchant->affiliate_network}].");
        $this->line('Total: '.count($items));
        $this->line("Valid: {$valid}");
        $this->line("Skipped: {$skipped}");

        foreach ($reasons as $reason) {
            $this->line('- '.$reason);
        }

        return self::SUCCESS;
    }
}
