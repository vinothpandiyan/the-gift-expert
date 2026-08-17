<?php

namespace App\Actions\Import;

use App\Enums\ImportRunItemStatus;
use App\Enums\ImportRunStatus;
use App\Import\CatalogProvider;
use App\Import\ImportedCatalogItem;
use App\Jobs\ProcessImportedCatalogItemJob;
use App\Models\ImportRun;
use App\Models\ImportRunItem;
use App\Models\Merchant;
use InvalidArgumentException;
use Throwable;

class ImportCatalogAction
{
    public function __construct(
        private ProcessImportedCatalogItemAction $processImportedCatalogItem,
    ) {}

    public function execute(Merchant $merchant): ImportRun
    {
        $run = ImportRun::query()->create([
            'merchant_id' => $merchant->id,
            'provider_key' => $merchant->affiliate_network,
            'status' => ImportRunStatus::Pending,
            'started_at' => null,
            'finished_at' => null,
        ]);

        try {
            $provider = $this->resolveProvider($merchant);
            $items = iterator_to_array($provider->eachProduct($merchant), false);
        } catch (Throwable $exception) {
            $run->status = ImportRunStatus::Failed;
            $run->finished_at = now();
            $run->error = $exception->getMessage();
            $run->save();

            throw $exception;
        }

        $pendingRecords = [];
        $skipped = 0;
        $seenExternalIds = [];

        foreach ($items as $item) {
            if (! $item instanceof ImportedCatalogItem || blank($item->external_product_id)) {
                $skipped++;

                continue;
            }

            if (isset($seenExternalIds[$item->external_product_id])) {
                $skipped++;

                continue;
            }

            $seenExternalIds[$item->external_product_id] = true;

            $pendingRecords[] = ImportRunItem::query()->create([
                'import_run_id' => $run->id,
                'external_product_id' => $item->external_product_id,
                'status' => ImportRunItemStatus::Pending,
                'source_payload' => $item->raw,
            ]);
        }

        $run->items_total = count($items);
        $run->items_skipped = $skipped;
        $run->status = ImportRunStatus::Running;
        $run->started_at = now();
        $run->save();

        foreach ($pendingRecords as $record) {
            ProcessImportedCatalogItemJob::dispatch($record->id);
        }

        if ($pendingRecords === []) {
            $this->processImportedCatalogItem->tryComplete($run->id);
        }

        return $run->fresh();
    }

    public function resolveProvider(Merchant $merchant): CatalogProvider
    {
        $key = $merchant->affiliate_network;
        $class = config('import.providers.'.$key.'.class');

        if (! is_string($class) || $class === '' || ! is_a($class, CatalogProvider::class, true)) {
            throw new InvalidArgumentException("Unknown catalog import provider [{$key}].");
        }

        $provider = app($class);

        if (! $provider instanceof CatalogProvider) {
            throw new InvalidArgumentException("Unknown catalog import provider [{$key}].");
        }

        return $provider;
    }
}
