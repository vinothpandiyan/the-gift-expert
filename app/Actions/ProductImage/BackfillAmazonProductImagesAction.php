<?php

namespace App\Actions\ProductImage;

use App\Models\CuratedProductIntakeItem;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use App\ProductImage\AmazonProductImageBackfillResult;
use Illuminate\Support\Collection;

class BackfillAmazonProductImagesAction
{
    public function __construct(
        private NormalizeAmazonProductImageUrlAction $normalizeAmazonProductImageUrl,
        private ReplaceAutomaticallyAcquiredProductImageAction $replaceAutomaticallyAcquiredProductImage,
    ) {}

    public function execute(
        ?int $intakeRunId = null,
        ?int $productId = null,
        ?int $limit = null,
        bool $dryRun = false,
    ): AmazonProductImageBackfillResult {
        $images = $this->candidateImages($intakeRunId, $productId, $limit);

        $examined = 0;
        $replaced = 0;
        $skippedOperatorManaged = 0;
        $skippedNotAmazon = 0;
        $skippedAlreadyHighResolution = 0;
        $failed = 0;
        $failures = [];

        foreach ($images as $image) {
            $examined++;
            $product = $image->product;
            $merchant = $this->amazonMerchant($product);

            if (! $this->replaceAutomaticallyAcquiredProductImage->isAutomaticallyAcquired($image)) {
                $skippedOperatorManaged++;

                continue;
            }

            if (! $merchant instanceof Merchant) {
                $skippedNotAmazon++;

                continue;
            }

            $sourceUrl = trim((string) $image->source_url);
            $normalized = $this->normalizeAmazonProductImageUrl->execute($sourceUrl, $merchant);

            if (! $normalized->isAmazon) {
                $skippedNotAmazon++;

                continue;
            }

            if ($dryRun) {
                if (! $normalized->changed) {
                    $skippedAlreadyHighResolution++;

                    continue;
                }

                $replaced++;

                continue;
            }

            $status = $this->replaceAutomaticallyAcquiredProductImage->execute($image, $merchant);

            match ($status) {
                'replaced' => $replaced++,
                'skipped_operator_managed' => $skippedOperatorManaged++,
                'skipped_not_amazon' => $skippedNotAmazon++,
                'skipped_already_high_resolution' => $skippedAlreadyHighResolution++,
                default => $this->recordFailure($failures, $failed, $image, $status),
            };
        }

        return new AmazonProductImageBackfillResult(
            examined: $examined,
            replaced: $replaced,
            skippedOperatorManaged: $skippedOperatorManaged,
            skippedNotAmazon: $skippedNotAmazon,
            skippedAlreadyHighResolution: $skippedAlreadyHighResolution,
            failed: $failed,
            dryRun: $dryRun,
            failures: $failures,
        );
    }

    /**
     * @return Collection<int, ProductImage>
     */
    private function candidateImages(?int $intakeRunId, ?int $productId, ?int $limit): Collection
    {
        $query = ProductImage::query()
            ->with(['product.affiliateLinks.merchant'])
            ->whereNotNull('source_url')
            ->whereNotNull('acquired_at')
            ->orderBy('id');

        if ($intakeRunId !== null) {
            $productIds = CuratedProductIntakeItem::query()
                ->where('curated_product_intake_run_id', $intakeRunId)
                ->whereNotNull('product_id')
                ->distinct()
                ->pluck('product_id');

            $query->whereIn('product_id', $productIds);
        }

        if ($productId !== null) {
            $query->where('product_id', $productId);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    private function amazonMerchant(?Product $product): ?Merchant
    {
        if ($product === null) {
            return null;
        }

        $link = $product->affiliateLinks->firstWhere('is_primary', true)
            ?? $product->affiliateLinks->first();
        $merchant = $link?->merchant;

        if (! $merchant instanceof Merchant || ! $this->normalizeAmazonProductImageUrl->isAmazonMerchant($merchant)) {
            return null;
        }

        return $merchant;
    }

    /**
     * @param  list<array{product_id: int, image_id: int, reason: string}>  $failures
     */
    private function recordFailure(array &$failures, int &$failed, ProductImage $image, string $reason): void
    {
        $failed++;
        $failures[] = [
            'product_id' => (int) $image->product_id,
            'image_id' => (int) $image->id,
            'reason' => $reason,
        ];
    }
}
