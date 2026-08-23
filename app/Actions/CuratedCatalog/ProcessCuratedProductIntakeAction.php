<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\CuratedProductIntakeCommitResult;
use App\CuratedCatalog\CuratedProductIntakeItemResult;
use App\CuratedCatalog\CuratedProductIntakePreviewItem;
use App\Enums\CuratedProductIntakeItemOutcome;
use App\Enums\CuratedProductIntakeRunStatus;
use App\Enums\CuratedProductIntakeSourceType;
use App\Models\CuratedProductIntakeItem;
use App\Models\CuratedProductIntakeRun;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProcessCuratedProductIntakeAction
{
    public function __construct(
        private PreviewCuratedProductIntakeAction $preview,
        private CreateCuratedMerchantProductAction $create,
        private RefreshCuratedMerchantProductAction $refresh,
    ) {}

    public function execute(
        string $json,
        ?string $formMerchantSlug = null,
        ?string $formCurationGroup = null,
        ?int $createdByUserId = null,
    ): CuratedProductIntakeCommitResult {
        $preview = $this->preview->execute($json, $formMerchantSlug, $formCurationGroup);
        $merchant = Merchant::query()
            ->where('slug', $preview->merchantSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $maxPerCommit = max(1, (int) config('curated_catalog.max_items_per_commit', 25));
        $actionable = $preview->actionableItems();
        $selected = array_slice($actionable, 0, $maxPerCommit);
        $remaining = max(0, count($actionable) - count($selected));

        $run = CuratedProductIntakeRun::query()->create([
            'merchant_id' => $merchant->id,
            'source_type' => CuratedProductIntakeSourceType::BrowserJson,
            'status' => CuratedProductIntakeRunStatus::Processing,
            'started_at' => now(),
            'items_total' => count($preview->items),
            'created_by_user_id' => $createdByUserId,
        ]);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $processed = [];
        $selectedIndexes = array_flip(array_map(
            fn (CuratedProductIntakePreviewItem $item): int => $item->itemIndex,
            $selected,
        ));

        foreach ($preview->items as $previewItem) {
            if (isset($selectedIndexes[$previewItem->itemIndex])) {
                $result = $this->processPreviewItem($merchant, $previewItem);
            } elseif (in_array($previewItem->proposedAction, ['CREATE', 'UPDATE'], true)) {
                $result = new CuratedProductIntakeItemResult(
                    success: false,
                    outcome: 'skipped',
                    productId: $previewItem->productId,
                    affiliateLinkId: $previewItem->affiliateLinkId,
                    warnings: array_values(array_unique(array_merge(
                        $previewItem->warnings,
                        ['not_processed_this_commit'],
                    ))),
                    error: 'not_processed_this_commit',
                );
            } else {
                $result = $this->resultFromPreviewOnly($previewItem);
            }

            $this->persistIntakeItem($run, $previewItem, $result);

            match ($result->outcome) {
                'created' => $created++,
                'updated' => $updated++,
                'skipped' => $skipped++,
                default => $failed++,
            };

            $processed[] = [
                'item_index' => $previewItem->itemIndex,
                'external_product_id' => $previewItem->externalProductId(),
                'title' => $previewItem->title(),
                'outcome' => $result->outcome,
                'product_id' => $result->productId,
                'product_name' => $this->productName($result->productId),
                'affiliate_ready' => $result->affiliateLinkId !== null,
                'warnings' => $result->warnings,
                'error' => $result->error,
            ];
        }

        $run->update([
            'status' => $failed > 0
                ? CuratedProductIntakeRunStatus::CompletedWithErrors
                : CuratedProductIntakeRunStatus::Completed,
            'finished_at' => now(),
            'items_created' => $created,
            'items_updated' => $updated,
            'items_skipped' => $skipped,
            'items_failed' => $failed,
        ]);

        return new CuratedProductIntakeCommitResult(
            runId: $run->id,
            itemsProcessed: count($selected),
            itemsCreated: $created,
            itemsUpdated: $updated,
            itemsSkipped: $skipped,
            itemsFailed: $failed,
            itemsRemaining: $remaining,
            processedItems: $processed,
        );
    }

    private function processPreviewItem(Merchant $merchant, CuratedProductIntakePreviewItem $previewItem): CuratedProductIntakeItemResult
    {
        if ($previewItem->input === null) {
            return new CuratedProductIntakeItemResult(
                success: false,
                outcome: 'failed',
                productId: null,
                affiliateLinkId: null,
                warnings: $previewItem->warnings,
                error: $previewItem->error?->code ?? 'invalid_item',
            );
        }

        if ($previewItem->proposedAction === 'CREATE') {
            return $this->create->execute($merchant, $previewItem->input, $previewItem->warnings);
        }

        if ($previewItem->proposedAction === 'UPDATE') {
            return $this->refresh->execute($merchant, $previewItem->input, $previewItem->warnings);
        }

        return $this->resultFromPreviewOnly($previewItem);
    }

    private function resultFromPreviewOnly(CuratedProductIntakePreviewItem $previewItem): CuratedProductIntakeItemResult
    {
        $outcome = match ($previewItem->proposedAction) {
            'FAIL' => 'failed',
            default => 'skipped',
        };

        return new CuratedProductIntakeItemResult(
            success: false,
            outcome: $outcome,
            productId: $previewItem->productId,
            affiliateLinkId: $previewItem->affiliateLinkId,
            warnings: $previewItem->warnings,
            error: $previewItem->error?->code
                ?? ($previewItem->warnings[0] ?? ($outcome === 'failed' ? 'failed' : 'skipped')),
        );
    }

    private function persistIntakeItem(
        CuratedProductIntakeRun $run,
        CuratedProductIntakePreviewItem $previewItem,
        CuratedProductIntakeItemResult $result,
    ): void {
        DB::transaction(function () use ($run, $previewItem, $result): void {
            CuratedProductIntakeItem::query()->create([
                'curated_product_intake_run_id' => $run->id,
                'item_index' => $previewItem->itemIndex,
                'external_product_id' => $previewItem->externalProductId() ?? 'unknown',
                'product_id' => $result->productId,
                'affiliate_link_id' => $result->affiliateLinkId,
                'outcome' => CuratedProductIntakeItemOutcome::from($result->outcome),
                'source_payload' => $previewItem->input?->sourcePayload,
                'warnings' => $result->warnings !== [] ? $result->warnings : null,
                'error' => $result->error,
            ]);
        });
    }

    private function productName(?int $productId): ?string
    {
        if ($productId === null) {
            return null;
        }

        $name = Product::query()->whereKey($productId)->value('name');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
