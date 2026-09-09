<?php

namespace App\Actions\ProductImage;

use App\Actions\CuratedCatalog\ValidateCuratedProductImageUrlAction;
use App\Actions\Import\AcquireRemoteProductImageAction;
use App\Models\Merchant;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReplaceAutomaticallyAcquiredProductImageAction
{
    public function __construct(
        private NormalizeAmazonProductImageUrlAction $normalizeAmazonProductImageUrl,
        private ValidateCuratedProductImageUrlAction $validateUrl,
        private AcquireRemoteProductImageAction $acquireRemoteProductImage,
        private ProcessProductImageAction $processProductImage,
    ) {}

    public function execute(ProductImage $image, Merchant $merchant): string
    {
        if (! $this->isAutomaticallyAcquired($image)) {
            return 'skipped_operator_managed';
        }

        $sourceUrl = trim((string) $image->source_url);
        $normalized = $this->normalizeAmazonProductImageUrl->execute($sourceUrl, $merchant);

        if (! $normalized->isAmazon) {
            return 'skipped_not_amazon';
        }

        if (! $normalized->changed) {
            return 'skipped_already_high_resolution';
        }

        $allowedHosts = $this->allowedHostsForMerchant($merchant);

        if ($allowedHosts === []) {
            return 'failed';
        }

        $acquiredPath = null;

        try {
            $this->validateUrl->execute($normalized->url, $allowedHosts, httpsOnly: true);

            $acquired = $this->acquireRemoteProductImage->execute(
                $normalized->url,
                fn (string $url): mixed => $this->validateUrl->execute($url, $allowedHosts, httpsOnly: true),
            );
            $acquiredPath = $acquired->path;

            if ($acquired->contentHash === $image->content_hash && ! $normalized->changed) {
                return 'skipped_already_high_resolution';
            }

            $processed = $this->processProductImage->execute($acquired->path);
            $disk = $image->disk ?: (string) config('media.product_images.disk', 'public');
            $newPath = $this->storagePath((int) $image->product_id, $processed->extension);

            if (! Storage::disk($disk)->put($newPath, $processed->contents)) {
                throw ValidationException::withMessages([
                    'image' => ['The image could not be stored.'],
                ]);
            }

            $oldPath = $image->path;
            $image->path = $newPath;
            $image->source_url = $normalized->url;
            $image->content_hash = $acquired->contentHash;
            $image->acquired_at = now();
            $image->save();

            if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $newPath) {
                Storage::disk($disk)->delete($oldPath);
            }

            return 'replaced';
        } catch (Throwable) {
            return 'failed';
        } finally {
            if (is_string($acquiredPath) && is_file($acquiredPath)) {
                @unlink($acquiredPath);
            }
        }
    }

    public function isAutomaticallyAcquired(ProductImage $image): bool
    {
        return is_string($image->source_url)
            && trim($image->source_url) !== ''
            && $image->acquired_at !== null;
    }

    public function storedLongEdge(ProductImage $image): int
    {
        $disk = $image->disk ?: (string) config('media.product_images.disk', 'public');
        $path = $image->path;

        if (! is_string($path) || $path === '' || ! Storage::disk($disk)->exists($path)) {
            return 0;
        }

        $binary = Storage::disk($disk)->get($path);

        if (! is_string($binary) || $binary === '') {
            return 0;
        }

        $info = @getimagesizefromstring($binary);

        if ($info === false || ! isset($info[0], $info[1])) {
            return 0;
        }

        return max((int) $info[0], (int) $info[1]);
    }

    /**
     * @return list<string>
     */
    private function allowedHostsForMerchant(Merchant $merchant): array
    {
        $hosts = config('curated_catalog.image_acquisition.merchants.'.$merchant->slug.'.allowed_hosts', []);

        return is_array($hosts) ? array_values(array_filter($hosts, is_string(...))) : [];
    }

    private function storagePath(int $productId, string $extension): string
    {
        $template = (string) config('media.product_images.path');
        $filename = Str::uuid()->toString().'.'.$extension;

        return str_replace(
            ['{product_id}', '{filename}'],
            [(string) $productId, $filename],
            $template,
        );
    }
}
