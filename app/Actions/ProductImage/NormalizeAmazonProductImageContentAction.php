<?php

namespace App\Actions\ProductImage;

use App\Models\CuratedProductIntakeItem;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use App\ProductImage\AmazonImageContentNormalizationResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NormalizeAmazonProductImageContentAction
{
    public function __construct(
        private NormalizeAmazonProductImageUrlAction $normalizeAmazonProductImageUrl,
        private ReplaceAutomaticallyAcquiredProductImageAction $replaceAutomaticallyAcquiredProductImage,
        private DetectSafeOuterBackgroundTrimAction $detectSafeOuterBackgroundTrim,
        private ProcessProductImageAction $processProductImage,
    ) {}

    public function execute(
        ?int $intakeRunId = null,
        ?int $productId = null,
        ?int $limit = null,
        bool $dryRun = false,
    ): AmazonImageContentNormalizationResult {
        $images = $this->candidateImages($intakeRunId, $productId, $limit);
        $examined = 0;
        $normalized = 0;
        $skippedOperatorManaged = 0;
        $skippedNotAmazon = 0;
        $skippedUnsafe = 0;
        $skippedAlreadyNormalized = 0;
        $failed = 0;
        $before = [];
        $after = [];
        $skips = [];
        $failures = [];

        foreach ($images as $image) {
            $examined++;

            if (! $this->replaceAutomaticallyAcquiredProductImage->isAutomaticallyAcquired($image)) {
                $skippedOperatorManaged++;

                continue;
            }

            $merchant = $this->amazonMerchant($image->product);
            $source = $this->normalizeAmazonProductImageUrl->execute((string) $image->source_url, $merchant);

            if (! $merchant instanceof Merchant || ! $source->isAmazon) {
                $skippedNotAmazon++;

                continue;
            }

            $temporaryPath = null;

            try {
                $temporaryPath = $this->temporaryStoredImage($image);
                $plan = $this->detectSafeOuterBackgroundTrim->execute($temporaryPath);

                if (! $plan->shouldTrim) {
                    if ($plan->reason === 'already_normalized') {
                        $skippedAlreadyNormalized++;
                    } else {
                        $skippedUnsafe++;
                        $skips[] = [
                            'product_id' => (int) $image->product_id,
                            'image_id' => (int) $image->id,
                            'reason' => $plan->reason,
                        ];
                    }

                    continue;
                }

                $before[] = (float) $plan->occupancyBefore;
                $after[] = (float) $plan->occupancyAfter;

                if (! $dryRun) {
                    $this->replaceStoredDerivative($image, $temporaryPath, $plan->cropBox);
                }

                $normalized++;
            } catch (Throwable $exception) {
                $failed++;
                $failures[] = [
                    'product_id' => (int) $image->product_id,
                    'image_id' => (int) $image->id,
                    'reason' => $exception->getMessage(),
                ];
            } finally {
                if (is_string($temporaryPath) && is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }
        }

        return new AmazonImageContentNormalizationResult(
            examined: $examined,
            normalized: $normalized,
            skippedOperatorManaged: $skippedOperatorManaged,
            skippedNotAmazon: $skippedNotAmazon,
            skippedUnsafe: $skippedUnsafe,
            skippedAlreadyNormalized: $skippedAlreadyNormalized,
            failed: $failed,
            dryRun: $dryRun,
            averageOccupancyBefore: $this->average($before),
            averageOccupancyAfter: $this->average($after),
            skips: $skips,
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

        return $merchant instanceof Merchant
            && $this->normalizeAmazonProductImageUrl->isAmazonMerchant($merchant)
                ? $merchant
                : null;
    }

    private function temporaryStoredImage(ProductImage $image): string
    {
        $disk = $image->disk ?: (string) config('media.product_images.disk', 'public');
        $binary = Storage::disk($disk)->get($image->path);
        $path = tempnam(sys_get_temp_dir(), 'gift-image-normalize-');

        if ($path === false || file_put_contents($path, $binary) === false) {
            throw new RuntimeException('The stored image could not be prepared for analysis.');
        }

        return $path;
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}|null  $cropBox
     */
    private function replaceStoredDerivative(ProductImage $image, string $sourcePath, ?array $cropBox): void
    {
        if ($cropBox === null) {
            throw new RuntimeException('A safe crop box was not available.');
        }

        $processed = $this->processProductImage->execute($sourcePath, $cropBox);
        $disk = $image->disk ?: (string) config('media.product_images.disk', 'public');
        $newPath = $this->storagePath((int) $image->product_id, $processed->extension);

        if (! Storage::disk($disk)->put($newPath, $processed->contents)) {
            throw new RuntimeException('The normalized image could not be stored.');
        }

        $oldPath = $image->path;

        try {
            $image->path = $newPath;
            $image->save();
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($newPath);

            throw $exception;
        }

        if ($oldPath !== $newPath) {
            Storage::disk($disk)->delete($oldPath);
        }
    }

    private function storagePath(int $productId, string $extension): string
    {
        return str_replace(
            ['{product_id}', '{filename}'],
            [(string) $productId, Str::uuid()->toString().'.'.$extension],
            (string) config('media.product_images.path'),
        );
    }

    /**
     * @param  list<float>  $values
     */
    private function average(array $values): float
    {
        return $values === [] ? 0 : array_sum($values) / count($values);
    }
}
