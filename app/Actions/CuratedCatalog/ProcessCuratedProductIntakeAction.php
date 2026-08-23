<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\CuratedImageAcquisitionOutcome;
use App\CuratedCatalog\CuratedProductIntakeCommitResult;
use App\CuratedCatalog\CuratedProductIntakeItemResult;
use App\CuratedCatalog\CuratedProductIntakePreviewItem;
use App\CuratedCatalog\CuratedProductIntakeStartedResult;
use App\Enums\CuratedProductIntakeItemOutcome;
use App\Enums\CuratedProductIntakeRunStatus;
use App\Enums\CuratedProductIntakeSourceType;
use App\Jobs\ProcessCuratedProductIntakeRunJob;
use App\Models\CuratedProductIntakeItem;
use App\Models\CuratedProductIntakeRun;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessCuratedProductIntakeAction
{
    public function __construct(
        private PreviewCuratedProductIntakeAction $preview,
        private CreateCuratedMerchantProductAction $create,
        private RefreshCuratedMerchantProductAction $refresh,
    ) {}

    public function start(
        string $json,
        ?string $formMerchantSlug = null,
        ?string $formCurationGroup = null,
        ?int $createdByUserId = null,
    ): CuratedProductIntakeStartedResult {
        $preview = $this->preview->execute($json, $formMerchantSlug, $formCurationGroup);
        $merchant = Merchant::query()
            ->where('slug', $preview->merchantSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $run = CuratedProductIntakeRun::query()->create([
            'merchant_id' => $merchant->id,
            'source_type' => CuratedProductIntakeSourceType::BrowserJson,
            'status' => CuratedProductIntakeRunStatus::Processing,
            'started_at' => now(),
            'items_total' => count($preview->items),
            'created_by_user_id' => $createdByUserId,
        ]);

        ProcessCuratedProductIntakeRunJob::dispatch(
            $run->id,
            $json,
            $formMerchantSlug,
            $formCurationGroup,
        );

        return new CuratedProductIntakeStartedResult(
            runId: $run->id,
            itemsTotal: count($preview->items),
            itemsActionable: $preview->itemsActionable,
        );
    }

    public function processRun(
        int $runId,
        string $json,
        ?string $formMerchantSlug = null,
        ?string $formCurationGroup = null,
    ): void {
        $run = CuratedProductIntakeRun::query()->findOrFail($runId);

        if (in_array($run->status, [
            CuratedProductIntakeRunStatus::Completed,
            CuratedProductIntakeRunStatus::CompletedWithErrors,
        ], true)) {
            return;
        }

        if ($run->status === CuratedProductIntakeRunStatus::Failed) {
            $run->update([
                'status' => CuratedProductIntakeRunStatus::Processing,
                'error' => null,
            ]);
        }

        try {
            $preview = $this->preview->execute($json, $formMerchantSlug, $formCurationGroup);
            $merchant = Merchant::query()
                ->where('slug', $preview->merchantSlug)
                ->where('is_active', true)
                ->firstOrFail();

            foreach ($preview->items as $previewItem) {
                if ($this->intakeItemAlreadyRecorded($run, $previewItem->itemIndex)) {
                    continue;
                }

                if (in_array($previewItem->proposedAction, ['CREATE', 'UPDATE'], true)) {
                    $result = $this->processPreviewItem($merchant, $previewItem);
                } else {
                    $result = $this->resultFromPreviewOnly($previewItem);
                }

                $this->persistIntakeItem($run, $previewItem, $result);
            }

            $created = $run->items()->where('outcome', CuratedProductIntakeItemOutcome::Created)->count();
            $updated = $run->items()->where('outcome', CuratedProductIntakeItemOutcome::Updated)->count();
            $skipped = $run->items()->where('outcome', CuratedProductIntakeItemOutcome::Skipped)->count();
            $failed = $run->items()->where('outcome', CuratedProductIntakeItemOutcome::Failed)->count();

            $run->update([
                'status' => $failed > 0
                    ? CuratedProductIntakeRunStatus::CompletedWithErrors
                    : CuratedProductIntakeRunStatus::Completed,
                'finished_at' => now(),
                'items_total' => count($preview->items),
                'items_created' => $created,
                'items_updated' => $updated,
                'items_skipped' => $skipped,
                'items_failed' => $failed,
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => CuratedProductIntakeRunStatus::Failed,
                'finished_at' => now(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function resultFromRun(CuratedProductIntakeRun $run): CuratedProductIntakeCommitResult
    {
        $run->load(['items' => fn ($query) => $query->orderBy('item_index')]);

        $processed = [];

        foreach ($run->items as $item) {
            $warnings = is_array($item->warnings) ? $item->warnings : [];
            [$imageStatus] = $this->resolveImagePresentation($warnings);

            $processed[] = [
                'item_index' => $item->item_index,
                'external_product_id' => $item->external_product_id,
                'title' => $this->titleFromPayload($item->source_payload),
                'outcome' => $item->outcome->value,
                'product_id' => $item->product_id,
                'product_name' => $this->productName($item->product_id),
                'affiliate_ready' => $item->affiliate_link_id !== null,
                'image_status' => $imageStatus,
                'warnings' => $warnings,
                'error' => $item->error,
            ];
        }

        $actionableProcessed = collect($processed)
            ->filter(fn (array $row): bool => in_array($row['outcome'], ['created', 'updated'], true))
            ->count();

        [$itemsCreated, $itemsUpdated, $itemsSkipped, $itemsFailed] = $this->summaryCountsFromRun($run);

        return new CuratedProductIntakeCommitResult(
            runId: $run->id,
            itemsProcessed: $actionableProcessed,
            itemsCreated: $itemsCreated,
            itemsUpdated: $itemsUpdated,
            itemsSkipped: $itemsSkipped,
            itemsFailed: $itemsFailed,
            processedItems: $processed,
            status: $run->status->value,
            isComplete: in_array($run->status, [
                CuratedProductIntakeRunStatus::Completed,
                CuratedProductIntakeRunStatus::CompletedWithErrors,
                CuratedProductIntakeRunStatus::Failed,
            ], true),
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function summaryCountsFromRun(CuratedProductIntakeRun $run): array
    {
        if ($run->status === CuratedProductIntakeRunStatus::Failed) {
            return [
                $run->items->where('outcome', CuratedProductIntakeItemOutcome::Created)->count(),
                $run->items->where('outcome', CuratedProductIntakeItemOutcome::Updated)->count(),
                $run->items->where('outcome', CuratedProductIntakeItemOutcome::Skipped)->count(),
                $run->items->where('outcome', CuratedProductIntakeItemOutcome::Failed)->count(),
            ];
        }

        return [
            (int) $run->items_created,
            (int) $run->items_updated,
            (int) $run->items_skipped,
            (int) $run->items_failed,
        ];
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
                imageStatus: null,
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
            imageStatus: null,
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

    private function intakeItemAlreadyRecorded(CuratedProductIntakeRun $run, int $itemIndex): bool
    {
        return CuratedProductIntakeItem::query()
            ->where('curated_product_intake_run_id', $run->id)
            ->where('item_index', $itemIndex)
            ->exists();
    }

    private function runIsTerminal(CuratedProductIntakeRun $run): bool
    {
        return in_array($run->status, [
            CuratedProductIntakeRunStatus::Completed,
            CuratedProductIntakeRunStatus::CompletedWithErrors,
        ], true);
    }

    /**
     * @param  list<string>  $warnings
     * @return array{0: ?string, 1: list<string>, 2: list<string>}
     */
    public function resolveImagePresentation(array $warnings): array
    {
        $imageCodes = [
            CuratedImageAcquisitionOutcome::STATUS_ACQUIRED,
            CuratedImageAcquisitionOutcome::STATUS_ALREADY_PRESENT,
            CuratedImageAcquisitionOutcome::STATUS_MISSING_SOURCE,
            CuratedImageAcquisitionOutcome::STATUS_FAILED,
        ];

        $imageStatus = null;

        foreach ($imageCodes as $code) {
            if (in_array($code, $warnings, true)) {
                $imageStatus = $code;

                break;
            }
        }

        $warningCodes = array_values(array_filter(
            $warnings,
            fn (string $code): bool => ! in_array($code, [
                CuratedImageAcquisitionOutcome::STATUS_ACQUIRED,
                CuratedImageAcquisitionOutcome::STATUS_ALREADY_PRESENT,
            ], true),
        ));

        $infoCodes = array_values(array_filter(
            $warnings,
            fn (string $code): bool => in_array($code, [
                CuratedImageAcquisitionOutcome::STATUS_ACQUIRED,
                CuratedImageAcquisitionOutcome::STATUS_ALREADY_PRESENT,
            ], true),
        ));

        return [$imageStatus, $warningCodes, $infoCodes];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function titleFromPayload(?array $payload): ?string
    {
        $title = $payload['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : null;
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
