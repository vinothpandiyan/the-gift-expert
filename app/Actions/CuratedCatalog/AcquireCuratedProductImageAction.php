<?php

namespace App\Actions\CuratedCatalog;

use App\Actions\Import\AcquireRemoteProductImageAction;
use App\Actions\ProductImage\StoreProductImageAction;
use App\CuratedCatalog\CuratedImageAcquisitionOutcome;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class AcquireCuratedProductImageAction
{
    public function __construct(
        private ValidateCuratedProductImageUrlAction $validateUrl,
        private AcquireRemoteProductImageAction $acquireRemoteProductImage,
        private StoreProductImageAction $storeProductImage,
    ) {}

    public function execute(Merchant $merchant, Product $product, ?string $sourceImageUrl): CuratedImageAcquisitionOutcome
    {
        if ($this->hasValidPrimaryImage($product)) {
            return new CuratedImageAcquisitionOutcome(CuratedImageAcquisitionOutcome::STATUS_ALREADY_PRESENT);
        }

        if (! is_string($sourceImageUrl) || trim($sourceImageUrl) === '') {
            return new CuratedImageAcquisitionOutcome(CuratedImageAcquisitionOutcome::STATUS_MISSING_SOURCE);
        }

        $allowedHosts = $this->allowedHostsForMerchant($merchant);

        if ($allowedHosts === []) {
            return new CuratedImageAcquisitionOutcome(
                CuratedImageAcquisitionOutcome::STATUS_FAILED,
                'No image hosts are configured for this merchant.',
            );
        }

        $acquiredPath = null;

        try {
            $this->validateUrl->execute($sourceImageUrl, $allowedHosts, httpsOnly: true);

            $acquired = $this->acquireRemoteProductImage->execute(
                $sourceImageUrl,
                fn (string $url): mixed => $this->validateUrl->execute($url, $allowedHosts, httpsOnly: true),
            );
            $acquiredPath = $acquired->path;

            $duplicate = ProductImage::query()
                ->where('product_id', $product->id)
                ->where('content_hash', $acquired->contentHash)
                ->exists();

            if ($duplicate) {
                return new CuratedImageAcquisitionOutcome(CuratedImageAcquisitionOutcome::STATUS_ALREADY_PRESENT);
            }

            $stored = $this->storeProductImage->execute(
                $product,
                [$acquired->path],
                altText: $product->name,
                preferPrimary: true,
            );

            $image = $stored->first();

            if ($image instanceof ProductImage) {
                $image->source_url = $sourceImageUrl;
                $image->content_hash = $acquired->contentHash;
                $image->acquired_at = now();
                $image->save();
            }

            return new CuratedImageAcquisitionOutcome(CuratedImageAcquisitionOutcome::STATUS_ACQUIRED);
        } catch (ValidationException $exception) {
            return new CuratedImageAcquisitionOutcome(
                CuratedImageAcquisitionOutcome::STATUS_FAILED,
                $this->firstValidationMessage($exception),
            );
        } catch (Throwable $exception) {
            return new CuratedImageAcquisitionOutcome(
                CuratedImageAcquisitionOutcome::STATUS_FAILED,
                $exception->getMessage(),
            );
        } finally {
            if (is_string($acquiredPath) && is_file($acquiredPath)) {
                @unlink($acquiredPath);
            }
        }
    }

    private function hasValidPrimaryImage(Product $product): bool
    {
        $primary = $product->images()->where('is_primary', true)->first();

        if (! $primary instanceof ProductImage) {
            return false;
        }

        $disk = $primary->disk ?: (string) config('media.product_images.disk', 'public');

        return Storage::disk($disk)->exists($primary->path);
    }

    /**
     * @return list<string>
     */
    private function allowedHostsForMerchant(Merchant $merchant): array
    {
        $hosts = config('curated_catalog.image_acquisition.merchants.'.$merchant->slug.'.allowed_hosts', []);

        return is_array($hosts) ? array_values(array_filter($hosts, is_string(...))) : [];
    }

    private function firstValidationMessage(ValidationException $exception): string
    {
        $errors = $exception->errors();
        $flat = collect($errors)->flatten()->filter()->values();

        if ($flat->isNotEmpty()) {
            return (string) $flat->first();
        }

        return $exception->getMessage();
    }
}
