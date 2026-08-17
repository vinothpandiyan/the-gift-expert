<?php

namespace App\Jobs;

use App\Actions\Import\ProcessImportedCatalogItemAction;
use App\Models\ImportRunItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Database queue deployments must set retry_after greater than this job's
 * timeout (120s). Otherwise a still-running import can be reserved twice.
 */
class ProcessImportedCatalogItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $importRunItemId) {}

    public function handle(ProcessImportedCatalogItemAction $processImportedCatalogItem): void
    {
        $processImportedCatalogItem->execute(
            ImportRunItem::query()->findOrFail($this->importRunItemId),
        );
    }

    public function failed(?Throwable $exception): void
    {
        $item = ImportRunItem::query()->find($this->importRunItemId);

        if ($item === null) {
            return;
        }

        app(ProcessImportedCatalogItemAction::class)->failPending(
            $item,
            $exception?->getMessage() ?? 'The import job failed.',
        );
    }
}
