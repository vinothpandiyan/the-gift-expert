<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product Images
    |--------------------------------------------------------------------------
    |
    | Canonical product-image processing is shared by Filament uploads and
    | any future product import. Filament is only the input UI; these values
    | are the source of truth for crop, resize, encode, and storage.
    |
    | Automatic crop (non-interactive / import): center crop to the canonical
    | aspect ratio. Manual Filament uploads use the image editor for a square
    | crop; the processing action still enforces the same ratio and will
    | center-crop if the source is not already canonical.
    |
    | Small sources are not upscaled. After cropping, an image whose side is
    | smaller than the canonical dimensions is stored at that cropped size.
    |
    */

    'product_images' => [
        'disk' => env('PRODUCT_IMAGE_DISK', 'public'),
        'path' => 'products/{product_id}/images/{filename}',
        'canonical_width' => 1600,
        'canonical_height' => 1600,
        'aspect_ratio' => '1:1',
        'output_format' => 'webp',
        'quality' => 82,
        'upscale' => false,
        'max_upload_kilobytes' => 8192,
        'max_files_per_upload' => 10,
        'max_images_per_product' => 20,
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
        ],
    ],

];
