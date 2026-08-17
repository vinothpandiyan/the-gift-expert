<?php

namespace App\Actions\Import;

use App\Enums\ImportRunItemStatus;
use App\Enums\ImportRunStatus;
use App\Import\ImportedCatalogItem;
use App\Import\ProviderImagePolicy;
use App\Models\ImportRun;
use App\Models\ImportRunItem;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessImportedCatalogItemAction
{
    public function __construct(
        private UpsertImportedProductAction $upsertImportedProduct,
        private StoreImportedProductImagesAction $storeImportedProductImages,
    ) {}

    public function execute(ImportRunItem $item): void
    {
        $item = $item->fresh();

        if ($item === null) {
            return;
        }

        if ($this->isTerminal($item)) {
            $this->tryComplete($item->import_run_id);

            return;
        }

        $payload = $item->source_payload;

        if (! is_array($payload)) {
            $this->failPending($item, 'The catalog item payload is missing.');

            return;
        }

        $catalogItem = ImportedCatalogItem::fromRow($payload);

        if (blank($catalogItem->name) || blank($catalogItem->affiliate_url)) {
            $this->transitionIfPending(
                $item,
                ImportRunItemStatus::Skipped,
                blank($catalogItem->name)
                    ? 'A gift name is required.'
                    : 'An affiliate URL is required.',
            );
            $this->tryComplete($item->import_run_id);

            return;
        }

        $item->loadMissing('importRun.merchant');
        $merchant = $item->importRun?->merchant;

        if ($merchant === null) {
            $this->failPending($item, 'The import run merchant could not be resolved.');

            return;
        }

        try {
            $link = $this->upsertImportedProduct->execute($merchant, $catalogItem);
        } catch (Throwable $exception) {
            $this->failPending($item, $exception->getMessage());

            return;
        }

        $imageNote = null;

        try {
            $policy = ProviderImagePolicy::forKey((string) $item->importRun->provider_key);
            $imageNote = $this->storeImportedProductImages->execute(
                $link->product,
                $catalogItem->image_urls,
                $policy,
            );
        } catch (Throwable $exception) {
            $imageNote = $exception->getMessage();
        }

        $this->transitionIfPending(
            $item,
            ImportRunItemStatus::Succeeded,
            $imageNote,
            $link->product_id,
            $link->id,
        );
        $this->tryComplete($item->import_run_id);
    }

    public function failPending(ImportRunItem $item, string $error): void
    {
        $this->transitionIfPending($item, ImportRunItemStatus::Failed, $error);
        $this->tryComplete($item->import_run_id);
    }

    public function tryComplete(int $importRunId): void
    {
        DB::transaction(function () use ($importRunId): void {
            $run = ImportRun::query()->whereKey($importRunId)->lockForUpdate()->first();

            if ($run === null || $run->status !== ImportRunStatus::Running) {
                return;
            }

            if ($run->items_succeeded + $run->items_failed + $run->items_skipped < $run->items_total) {
                return;
            }

            $run->status = ($run->items_failed === 0 && $run->items_skipped === 0)
                ? ImportRunStatus::Completed
                : ImportRunStatus::CompletedWithErrors;
            $run->finished_at = now();
            $run->save();
        });
    }

    private function isTerminal(ImportRunItem $item): bool
    {
        return in_array($item->status, [
            ImportRunItemStatus::Succeeded,
            ImportRunItemStatus::Failed,
            ImportRunItemStatus::Skipped,
        ], true);
    }

    private function transitionIfPending(
        ImportRunItem $item,
        ImportRunItemStatus $status,
        ?string $error = null,
        ?int $productId = null,
        ?int $affiliateLinkId = null,
    ): bool {
        return DB::transaction(function () use ($item, $status, $error, $productId, $affiliateLinkId): bool {
            $locked = ImportRunItem::query()->whereKey($item->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== ImportRunItemStatus::Pending) {
                return false;
            }

            $locked->status = $status;
            $locked->error = $error;

            if ($productId !== null) {
                $locked->product_id = $productId;
            }

            if ($affiliateLinkId !== null) {
                $locked->affiliate_link_id = $affiliateLinkId;
            }

            $locked->save();

            $counter = match ($status) {
                ImportRunItemStatus::Succeeded => 'items_succeeded',
                ImportRunItemStatus::Failed => 'items_failed',
                ImportRunItemStatus::Skipped => 'items_skipped',
                default => null,
            };

            if ($counter !== null) {
                ImportRun::query()->whereKey($locked->import_run_id)->increment($counter);
            }

            return true;
        });
    }
}
