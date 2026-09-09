<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\CuratedClassificationBatchResult;
use App\CuratedCatalog\CuratedClassificationPlan;
use App\Enums\TaxonomyClassificationStatus;
use App\Jobs\ClassifyCuratedMerchantProductJob;
use App\Models\Product;
use Throwable;

class ClassifyCuratedMerchantProductsBatchAction
{
    public function __construct(
        private ClassifyCuratedMerchantProductAction $classify,
    ) {}

    /**
     * @param  callable(array{
     *     processed: int,
     *     remaining: int,
     *     ai_accepted: int,
     *     review: int,
     *     failed: int,
     *     skipped_current: int,
     *     skipped_locked: int,
     *     product_id: int,
     *     status: string,
     *     reason: string
     * }): void|null  $onProgress
     */
    public function execute(
        CuratedClassificationPlan $plan,
        bool $force = false,
        bool $retryFailed = false,
        bool $queue = false,
        ?callable $onProgress = null,
    ): CuratedClassificationBatchResult {
        $eligibleIds = [];

        foreach ($plan->items as $item) {
            if ($item->eligible) {
                $eligibleIds[] = $item->productId;
            }
        }

        $skippedCurrent = $plan->skippedCurrent;
        $skippedLocked = $plan->skippedLocked;

        if ($queue) {
            foreach ($eligibleIds as $productId) {
                ClassifyCuratedMerchantProductJob::dispatch($productId, $force, $retryFailed);
            }

            return new CuratedClassificationBatchResult(
                totalEligible: count($eligibleIds),
                processed: 0,
                aiAccepted: 0,
                review: 0,
                failed: 0,
                skippedCurrent: $skippedCurrent,
                skippedLocked: $skippedLocked,
                remaining: count($eligibleIds),
                aiCalls: 0,
                acceptedProductIds: [],
                reviewProductIds: [],
                failedProductIds: [],
                queued: count($eligibleIds),
            );
        }

        $processed = 0;
        $accepted = 0;
        $review = 0;
        $failed = 0;
        $aiCalls = 0;
        $acceptedIds = [];
        $reviewIds = [];
        $failedIds = [];
        $remaining = count($eligibleIds);

        foreach ($eligibleIds as $productId) {
            $product = Product::query()
                ->with([
                    'affiliateLinks.merchant:id,slug,name',
                    'affiliateLinks.catalogProductSources.sourceList:id,merchant_id,kind,relationship_id,is_active,name',
                    'categories',
                ])
                ->find($productId);

            if (! $product instanceof Product) {
                $remaining--;

                continue;
            }

            try {
                $result = $this->classify->execute($product, $force, $retryFailed);
            } catch (Throwable $exception) {
                $failed++;
                $processed++;
                $remaining--;
                $failedIds[] = $productId;
                $onProgress?->__invoke([
                    'processed' => $processed,
                    'remaining' => $remaining,
                    'ai_accepted' => $accepted,
                    'review' => $review,
                    'failed' => $failed,
                    'skipped_current' => $skippedCurrent,
                    'skipped_locked' => $skippedLocked,
                    'product_id' => $productId,
                    'status' => TaxonomyClassificationStatus::Failed->value,
                    'reason' => $exception->getMessage(),
                ]);

                continue;
            }

            if (! $result->classified) {
                if ($result->reason === 'human_locked') {
                    $skippedLocked++;
                } else {
                    $skippedCurrent++;
                }

                $remaining--;

                continue;
            }

            $processed++;
            $aiCalls++;
            $remaining--;

            if ($result->status === TaxonomyClassificationStatus::AiAccepted) {
                $accepted++;
                $acceptedIds[] = $result->product->id;
            } elseif ($result->status === TaxonomyClassificationStatus::Review) {
                $review++;
                $reviewIds[] = $result->product->id;
            } elseif ($result->status === TaxonomyClassificationStatus::Failed) {
                $failed++;
                $failedIds[] = $result->product->id;
            }

            $onProgress?->__invoke([
                'processed' => $processed,
                'remaining' => $remaining,
                'ai_accepted' => $accepted,
                'review' => $review,
                'failed' => $failed,
                'skipped_current' => $skippedCurrent,
                'skipped_locked' => $skippedLocked,
                'product_id' => $result->product->id,
                'status' => $result->status->value,
                'reason' => $result->reason,
            ]);
        }

        return new CuratedClassificationBatchResult(
            totalEligible: count($eligibleIds),
            processed: $processed,
            aiAccepted: $accepted,
            review: $review,
            failed: $failed,
            skippedCurrent: $skippedCurrent,
            skippedLocked: $skippedLocked,
            remaining: $remaining,
            aiCalls: $aiCalls,
            acceptedProductIds: $acceptedIds,
            reviewProductIds: $reviewIds,
            failedProductIds: $failedIds,
        );
    }
}
