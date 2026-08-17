<?php

namespace App\Actions\Import;

use App\Actions\ProductImage\StoreProductImageAction;
use App\Import\ProviderImagePolicy;
use App\Models\Product;
use App\Models\ProductImage;
use Throwable;

class StoreImportedProductImagesAction
{
    public function __construct(
        private AcquireRemoteProductImageAction $acquireRemoteProductImage,
        private StoreProductImageAction $storeProductImage,
    ) {}

    /**
     * @param  list<string>  $imageUrls
     */
    public function execute(Product $product, array $imageUrls, ProviderImagePolicy $policy): ?string
    {
        if (! $policy->allowsLocalAcquisition()) {
            return 'Images skipped: provider policy does not permit local storage or transformation.';
        }

        $notes = [];
        $mediaMax = (int) config('media.product_images.max_images_per_product');
        $cap = min($policy->maxImages, $mediaMax);
        $remaining = max(0, $cap - $product->images()->count());
        $urls = array_slice(array_values($imageUrls), 0, $remaining);

        foreach ($urls as $url) {
            $acquiredPath = null;

            try {
                $acquired = $this->acquireRemoteProductImage->execute($url);
                $acquiredPath = $acquired->path;

                $duplicate = ProductImage::query()
                    ->where('product_id', $product->id)
                    ->where('content_hash', $acquired->contentHash)
                    ->exists();

                if ($duplicate) {
                    continue;
                }

                $stored = $this->storeProductImage->execute(
                    $product,
                    [$acquired->path],
                    altText: $product->name,
                    preferPrimary: false,
                );

                $image = $stored->first();

                if ($image instanceof ProductImage) {
                    $image->source_url = $url;
                    $image->content_hash = $acquired->contentHash;
                    $image->acquired_at = now();
                    $image->save();
                }
            } catch (Throwable $exception) {
                $notes[] = $this->noteForUrl($url, $exception);
            } finally {
                if (is_string($acquiredPath) && is_file($acquiredPath)) {
                    @unlink($acquiredPath);
                }
            }
        }

        if ($notes === []) {
            return null;
        }

        return implode(' ', $notes);
    }

    private function noteForUrl(string $url, Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (method_exists($exception, 'errors')) {
            $errors = $exception->errors();

            if (is_array($errors)) {
                $flat = collect($errors)->flatten()->filter()->values();

                if ($flat->isNotEmpty()) {
                    $message = (string) $flat->first();
                }
            }
        }

        return 'Image skipped ('.$url.'): '.$message;
    }
}
