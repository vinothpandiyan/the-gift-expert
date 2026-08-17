<?php

namespace App\Actions\ProductImage;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SplFileInfo;
use Throwable;

class StoreProductImageAction
{
    public function __construct(
        private ProcessProductImageAction $processProductImage,
        private SetPrimaryProductImageAction $setPrimaryProductImage,
    ) {}

    /**
     * @param  list<string|SplFileInfo>  $sources
     * @return Collection<int, ProductImage>
     */
    public function execute(
        Product $product,
        array $sources,
        ?string $altText = null,
        bool $preferPrimary = false,
    ): Collection {
        $sources = array_values($sources);

        if ($sources === []) {
            throw ValidationException::withMessages([
                'image' => ['Select at least one image to upload.'],
            ]);
        }

        $maxPerUpload = (int) config('media.product_images.max_files_per_upload');

        if (count($sources) > $maxPerUpload) {
            throw ValidationException::withMessages([
                'image' => ['Too many images in this upload.'],
            ]);
        }

        $maxPerProduct = (int) config('media.product_images.max_images_per_product');
        $existing = $product->images()->count();

        if (($existing + count($sources)) > $maxPerProduct) {
            throw ValidationException::withMessages([
                'image' => ['This gift already has the maximum number of images.'],
            ]);
        }

        $disk = (string) config('media.product_images.disk');
        $written = [];
        $created = collect();

        try {
            return DB::transaction(function () use ($product, $sources, $altText, $preferPrimary, $disk, &$written, $created): Collection {
                $nextSort = (int) $product->images()->max('sort_order');
                $hadPrimary = $product->images()->where('is_primary', true)->exists();

                foreach ($sources as $index => $source) {
                    $processed = $this->processProductImage->execute($source);
                    $path = $this->storagePath($product->id, $processed->extension);

                    if (! Storage::disk($disk)->put($path, $processed->contents)) {
                        throw ValidationException::withMessages([
                            'image' => ['The image could not be stored.'],
                        ]);
                    }

                    $written[] = $path;
                    $nextSort++;

                    $image = $product->images()->create([
                        'disk' => $disk,
                        'path' => $path,
                        'alt_text' => $altText,
                        'sort_order' => $nextSort,
                        'is_primary' => false,
                    ]);

                    $created->push($image);

                    $shouldBePrimary = (! $hadPrimary && $index === 0)
                        || ($preferPrimary && $index === 0);

                    if ($shouldBePrimary) {
                        $this->setPrimaryProductImage->execute($image);
                        $hadPrimary = true;
                    }
                }

                return $created;
            });
        } catch (Throwable $exception) {
            foreach ($written as $path) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }
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
