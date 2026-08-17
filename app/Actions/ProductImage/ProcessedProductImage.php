<?php

namespace App\Actions\ProductImage;

final class ProcessedProductImage
{
    public function __construct(
        public readonly string $contents,
        public readonly int $width,
        public readonly int $height,
        public readonly string $mimeType,
        public readonly string $extension,
    ) {}
}
