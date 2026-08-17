<?php

namespace Tests\Support;

trait MakesRasterImages
{
    private function rasterImagePath(int $width, int $height, string $format = 'png'): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle(
            $image,
            0,
            0,
            $width - 1,
            $height - 1,
            imagecolorallocate($image, 180, 50, 50),
        );

        $path = tempnam(sys_get_temp_dir(), 'gift-image-').'.'.$format;

        $written = match ($format) {
            'png' => imagepng($image, $path),
            'jpg', 'jpeg' => imagejpeg($image, $path, 90),
            'webp' => imagewebp($image, $path, 90),
            default => false,
        };

        imagedestroy($image);

        if ($written === false) {
            $this->fail('Unable to create a test raster image.');
        }

        return $path;
    }
}
