<?php

namespace App\Actions\ProductImage;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use SplFileInfo;

class ProcessProductImageAction
{
    /**
     * @param  array{x: int, y: int, width: int, height: int}|null  $cropBox
     */
    public function execute(string|SplFileInfo $source, ?array $cropBox = null): ProcessedProductImage
    {
        $path = $this->absolutePath($source);
        $this->assertReadableFile($path);
        $this->assertSize($path);

        $binary = file_get_contents($path);

        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages([
                'image' => ['The image could not be read.'],
            ]);
        }

        $this->assertAllowedMime($path, $binary);

        $info = getimagesizefromstring($binary);

        if ($info === false || ! isset($info[0], $info[1], $info['mime'])) {
            throw ValidationException::withMessages([
                'image' => ['The file is not a valid image.'],
            ]);
        }

        $sourceImage = @imagecreatefromstring($binary);

        if (! $sourceImage instanceof GdImage) {
            throw ValidationException::withMessages([
                'image' => ['The file is not a valid image.'],
            ]);
        }

        try {
            $sourceImage = $this->orientJpeg($sourceImage, $path, (string) $info['mime']);
            $cropped = $this->cropToCanonicalRatio($sourceImage, $cropBox);
            $resized = $this->resizeWithoutUnnecessaryUpscale($cropped);

            return $this->encode($resized);
        } finally {
            if ($sourceImage instanceof GdImage) {
                imagedestroy($sourceImage);
            }
        }
    }

    private function absolutePath(string|SplFileInfo $source): string
    {
        if ($source instanceof UploadedFile) {
            $path = $source->getRealPath() ?: $source->getPathname();
        } elseif ($source instanceof SplFileInfo) {
            $path = $source->getRealPath() ?: $source->getPathname();
        } else {
            $path = $source;
        }

        return $path;
    }

    private function assertReadableFile(string $path): void
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'image' => ['The image could not be read.'],
            ]);
        }
    }

    private function assertSize(string $path): void
    {
        $maxBytes = (int) config('media.product_images.max_upload_kilobytes') * 1024;
        $size = filesize($path);

        if ($size === false || $size > $maxBytes) {
            throw ValidationException::withMessages([
                'image' => ['The image exceeds the maximum upload size.'],
            ]);
        }
    }

    private function assertAllowedMime(string $path, string $binary): void
    {
        $allowed = config('media.product_images.allowed_mime_types', []);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($binary) ?: $finfo->file($path);

        if (! is_string($mime) || ! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages([
                'image' => ['The file type is not an allowed image format.'],
            ]);
        }
    }

    private function orientJpeg(GdImage $image, string $path, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => false,
        };

        if ($rotated instanceof GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}|null  $cropBox
     */
    private function cropToCanonicalRatio(GdImage $image, ?array $cropBox): GdImage
    {
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);

        if ($cropBox !== null) {
            $x = max(0, (int) $cropBox['x']);
            $y = max(0, (int) $cropBox['y']);
            $width = max(1, (int) $cropBox['width']);
            $height = max(1, (int) $cropBox['height']);
            $width = min($width, $sourceWidth - $x);
            $height = min($height, $sourceHeight - $y);
        } else {
            [$x, $y, $width, $height] = $this->centerCropBox($sourceWidth, $sourceHeight);
        }

        $cropped = imagecrop($image, [
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
        ]);

        if (! $cropped instanceof GdImage) {
            throw ValidationException::withMessages([
                'image' => ['The image could not be cropped.'],
            ]);
        }

        if ($this->isCanonicalRatio($width, $height)) {
            return $cropped;
        }

        [$innerX, $innerY, $innerWidth, $innerHeight] = $this->centerCropBox($width, $height);
        $squared = imagecrop($cropped, [
            'x' => $innerX,
            'y' => $innerY,
            'width' => $innerWidth,
            'height' => $innerHeight,
        ]);
        imagedestroy($cropped);

        if (! $squared instanceof GdImage) {
            throw ValidationException::withMessages([
                'image' => ['The image could not be cropped.'],
            ]);
        }

        return $squared;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function centerCropBox(int $width, int $height): array
    {
        $targetRatio = $this->canonicalRatio();
        $sourceRatio = $width / max($height, 1);

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $height;
            $cropWidth = (int) round($height * $targetRatio);
            $x = (int) (($width - $cropWidth) / 2);
            $y = 0;
        } else {
            $cropWidth = $width;
            $cropHeight = (int) round($width / $targetRatio);
            $x = 0;
            $y = (int) (($height - $cropHeight) / 2);
        }

        return [
            $x,
            $y,
            max(1, $cropWidth),
            max(1, $cropHeight),
        ];
    }

    private function resizeWithoutUnnecessaryUpscale(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $canonicalWidth = (int) config('media.product_images.canonical_width');
        $canonicalHeight = (int) config('media.product_images.canonical_height');
        $upscale = (bool) config('media.product_images.upscale');

        $scale = min($canonicalWidth / max($width, 1), $canonicalHeight / max($height, 1));

        if ($scale >= 1 && ! $upscale) {
            return $image;
        }

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }

    private function encode(GdImage $image): ProcessedProductImage
    {
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $format = strtolower((string) config('media.product_images.output_format'));
        $quality = (int) config('media.product_images.quality');
        $width = imagesx($image);
        $height = imagesy($image);

        ob_start();

        $encoded = match ($format) {
            'webp' => imagewebp($image, null, $quality),
            'jpeg', 'jpg' => imagejpeg($image, null, $quality),
            'png' => imagepng($image, null, (int) round((100 - $quality) / 11.111)),
            default => false,
        };

        $contents = (string) ob_get_clean();
        imagedestroy($image);

        if ($encoded === false || $contents === '') {
            throw ValidationException::withMessages([
                'image' => ['The image could not be encoded.'],
            ]);
        }

        $extension = $format === 'jpg' ? 'jpeg' : $format;
        $mimeType = match ($extension) {
            'webp' => 'image/webp',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return new ProcessedProductImage(
            contents: $contents,
            width: $width,
            height: $height,
            mimeType: $mimeType,
            extension: $extension === 'jpeg' ? 'jpg' : $extension,
        );
    }

    private function canonicalRatio(): float
    {
        $width = max(1, (int) config('media.product_images.canonical_width'));
        $height = max(1, (int) config('media.product_images.canonical_height'));

        return $width / $height;
    }

    private function isCanonicalRatio(int $width, int $height): bool
    {
        $expected = $this->canonicalRatio();
        $actual = $width / max($height, 1);

        return abs($expected - $actual) < 0.02;
    }
}
