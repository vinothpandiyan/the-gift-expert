<?php

namespace App\Observers;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductImageObserver
{
    public function deleted(ProductImage $image): void
    {
        $this->deleteStoredFile($image);
    }

    public function forceDeleted(ProductImage $image): void
    {
        $this->deleteStoredFile($image);
    }

    private function deleteStoredFile(ProductImage $image): void
    {
        $disk = $image->disk ?: (string) config('media.product_images.disk');
        $path = $image->path;

        if (! is_string($path) || $path === '') {
            return;
        }

        try {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (Throwable) {
            // Missing or unreadable files must not block record deletion.
        }
    }
}
