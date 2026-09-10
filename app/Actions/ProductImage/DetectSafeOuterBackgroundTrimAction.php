<?php

namespace App\Actions\ProductImage;

use App\ProductImage\SafeOuterBackgroundTrimPlan;
use GdImage;
use SplFileInfo;

class DetectSafeOuterBackgroundTrimAction
{
    public function execute(string|SplFileInfo $source): SafeOuterBackgroundTrimPlan
    {
        $path = $source instanceof SplFileInfo
            ? ($source->getRealPath() ?: $source->getPathname())
            : $source;
        $binary = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        $image = is_string($binary) && $binary !== '' ? @imagecreatefromstring($binary) : false;

        if (! $image instanceof GdImage) {
            return new SafeOuterBackgroundTrimPlan(false, 'unreadable', 0, 0);
        }

        try {
            return $this->plan($image);
        } finally {
            imagedestroy($image);
        }
    }

    private function plan(GdImage $image): SafeOuterBackgroundTrimPlan
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (! (bool) config('curated_catalog.image_acquisition.amazon.content_trim.enabled', false)) {
            return new SafeOuterBackgroundTrimPlan(false, 'disabled', $width, $height);
        }

        if ($width < 2 || $height < 2) {
            return new SafeOuterBackgroundTrimPlan(false, 'too_small', $width, $height);
        }

        $ratioTolerance = (float) config('curated_catalog.image_acquisition.amazon.content_trim.square_ratio_tolerance', 0.02);

        if (abs(($width / $height) - 1) > $ratioTolerance) {
            return new SafeOuterBackgroundTrimPlan(false, 'non_square_source', $width, $height);
        }

        $cornerColors = $this->cornerColors($image);
        $cornerTolerance = (int) config('curated_catalog.image_acquisition.amazon.content_trim.corner_color_tolerance', 10);

        if (! $this->colorsAreSimilar($cornerColors, $cornerTolerance)) {
            return new SafeOuterBackgroundTrimPlan(false, 'non_uniform_corners', $width, $height);
        }

        $background = $this->averageColor($cornerColors);
        $minimumBackgroundChannel = (int) config('curated_catalog.image_acquisition.amazon.content_trim.minimum_background_channel', 245);

        if (! $this->isConfidentArtificialBackground($background, $minimumBackgroundChannel)) {
            return new SafeOuterBackgroundTrimPlan(false, 'background_not_confidently_artificial', $width, $height);
        }

        $backgroundTolerance = (int) config('curated_catalog.image_acquisition.amazon.content_trim.background_color_tolerance', 18);
        $minimumBorderRatio = (float) config('curated_catalog.image_acquisition.amazon.content_trim.minimum_uniform_border_ratio', 0.98);

        if ($this->uniformBorderRatio($image, $background, $backgroundTolerance) < $minimumBorderRatio) {
            return new SafeOuterBackgroundTrimPlan(false, 'non_uniform_border', $width, $height);
        }

        $contentBox = $this->contentBox($image, $background, $backgroundTolerance);

        if ($contentBox === null) {
            return new SafeOuterBackgroundTrimPlan(false, 'no_distinct_content', $width, $height);
        }

        $contentLongEdge = max($contentBox['width'], $contentBox['height']);
        $minimumCanvas = max(1, (int) config('curated_catalog.image_acquisition.amazon.content_trim.minimum_canvas_edge', 600));
        $marginRatio = max(0, (float) config('curated_catalog.image_acquisition.amazon.content_trim.safety_margin_ratio', 0.06));
        $minimumMargin = max(0, (int) config('curated_catalog.image_acquisition.amazon.content_trim.minimum_safety_margin', 12));
        $margin = max($minimumMargin, (int) ceil($contentLongEdge * $marginRatio));
        $side = max($minimumCanvas, $contentLongEdge + ($margin * 2));
        $side = min($side, min($width, $height));
        $maximumCanvasRatio = (float) config('curated_catalog.image_acquisition.amazon.content_trim.maximum_canvas_ratio', 0.94);

        if ($side >= (min($width, $height) * $maximumCanvasRatio)) {
            return new SafeOuterBackgroundTrimPlan(
                false,
                'already_normalized',
                $width,
                $height,
                $contentBox,
                occupancyBefore: $contentLongEdge / min($width, $height),
            );
        }

        $centerX = $contentBox['x'] + ($contentBox['width'] / 2);
        $centerY = $contentBox['y'] + ($contentBox['height'] / 2);
        $x = max(0, min($width - $side, (int) round($centerX - ($side / 2))));
        $y = max(0, min($height - $side, (int) round($centerY - ($side / 2))));
        $cropBox = ['x' => $x, 'y' => $y, 'width' => $side, 'height' => $side];

        if (! $this->containsWithMargin($cropBox, $contentBox, $minimumMargin)) {
            return new SafeOuterBackgroundTrimPlan(
                false,
                'insufficient_safety_margin',
                $width,
                $height,
                $contentBox,
                occupancyBefore: $contentLongEdge / min($width, $height),
            );
        }

        return new SafeOuterBackgroundTrimPlan(
            true,
            'safe_uniform_outer_background',
            $width,
            $height,
            $contentBox,
            $cropBox,
            $contentLongEdge / min($width, $height),
            $contentLongEdge / $side,
        );
    }

    /**
     * @return list<array{red: int, green: int, blue: int, alpha: int}>
     */
    private function cornerColors(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $sampleSize = max(2, min(12, (int) floor(min($width, $height) * 0.01)));

        return [
            $this->averageRegion($image, 0, 0, $sampleSize, $sampleSize),
            $this->averageRegion($image, $width - $sampleSize, 0, $sampleSize, $sampleSize),
            $this->averageRegion($image, 0, $height - $sampleSize, $sampleSize, $sampleSize),
            $this->averageRegion($image, $width - $sampleSize, $height - $sampleSize, $sampleSize, $sampleSize),
        ];
    }

    /**
     * @return array{red: int, green: int, blue: int, alpha: int}
     */
    private function averageRegion(GdImage $image, int $x, int $y, int $width, int $height): array
    {
        $totals = ['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 0];
        $count = $width * $height;

        for ($row = $y; $row < $y + $height; $row++) {
            for ($column = $x; $column < $x + $width; $column++) {
                $color = $this->colorAt($image, $column, $row);

                foreach ($totals as $channel => $value) {
                    $totals[$channel] = $value + $color[$channel];
                }
            }
        }

        return array_map(fn (int $total): int => (int) round($total / $count), $totals);
    }

    /**
     * @param  list<array{red: int, green: int, blue: int, alpha: int}>  $colors
     */
    private function colorsAreSimilar(array $colors, int $tolerance): bool
    {
        foreach (['red', 'green', 'blue', 'alpha'] as $channel) {
            $values = array_column($colors, $channel);

            if ((max($values) - min($values)) > $tolerance) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{red: int, green: int, blue: int, alpha: int}>  $colors
     * @return array{red: int, green: int, blue: int, alpha: int}
     */
    private function averageColor(array $colors): array
    {
        return [
            'red' => (int) round(array_sum(array_column($colors, 'red')) / count($colors)),
            'green' => (int) round(array_sum(array_column($colors, 'green')) / count($colors)),
            'blue' => (int) round(array_sum(array_column($colors, 'blue')) / count($colors)),
            'alpha' => (int) round(array_sum(array_column($colors, 'alpha')) / count($colors)),
        ];
    }

    /**
     * @param  array{red: int, green: int, blue: int, alpha: int}  $background
     */
    private function uniformBorderRatio(GdImage $image, array $background, int $tolerance): float
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, (int) floor(min($width, $height) / 300));
        $depth = max(1, (int) floor(
            min($width, $height)
            * (float) config('curated_catalog.image_acquisition.amazon.content_trim.uniform_border_depth_ratio', 0.015),
        ));
        $backgroundPixels = 0;
        $sampledPixels = 0;

        for ($x = 0; $x < $width; $x += $step) {
            for ($inset = 0; $inset < $depth; $inset++) {
                foreach ([$inset, $height - 1 - $inset] as $y) {
                    $sampledPixels++;
                    $backgroundPixels += $this->isBackground($this->colorAt($image, $x, $y), $background, $tolerance) ? 1 : 0;
                }
            }
        }

        for ($y = 0; $y < $height; $y += $step) {
            for ($inset = 0; $inset < $depth; $inset++) {
                foreach ([$inset, $width - 1 - $inset] as $x) {
                    $sampledPixels++;
                    $backgroundPixels += $this->isBackground($this->colorAt($image, $x, $y), $background, $tolerance) ? 1 : 0;
                }
            }
        }

        return $sampledPixels === 0 ? 0 : $backgroundPixels / $sampledPixels;
    }

    /**
     * @param  array{red: int, green: int, blue: int, alpha: int}  $background
     */
    private function isConfidentArtificialBackground(array $background, int $minimumChannel): bool
    {
        if ($background['alpha'] >= 120) {
            return true;
        }

        return $background['red'] >= $minimumChannel
            && $background['green'] >= $minimumChannel
            && $background['blue'] >= $minimumChannel;
    }

    /**
     * @param  array{red: int, green: int, blue: int, alpha: int}  $background
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private function contentBox(GdImage $image, array $background, int $tolerance): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;
        $step = max(1, (int) config('curated_catalog.image_acquisition.amazon.content_trim.content_scan_step', 3));

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                if ($this->isBackground($this->colorAt($image, $x, $y), $background, $tolerance)) {
                    continue;
                }

                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }

        if ($maxX < $minX || $maxY < $minY) {
            return null;
        }

        $minX = max(0, $minX - $step);
        $minY = max(0, $minY - $step);
        $maxX = min($width - 1, $maxX + $step);
        $maxY = min($height - 1, $maxY + $step);

        return [
            'x' => $minX,
            'y' => $minY,
            'width' => ($maxX - $minX) + 1,
            'height' => ($maxY - $minY) + 1,
        ];
    }

    /**
     * @param  array{red: int, green: int, blue: int, alpha: int}  $color
     * @param  array{red: int, green: int, blue: int, alpha: int}  $background
     */
    private function isBackground(array $color, array $background, int $tolerance): bool
    {
        return abs($color['red'] - $background['red']) <= $tolerance
            && abs($color['green'] - $background['green']) <= $tolerance
            && abs($color['blue'] - $background['blue']) <= $tolerance
            && abs($color['alpha'] - $background['alpha']) <= 5;
    }

    /**
     * @return array{red: int, green: int, blue: int, alpha: int}
     */
    private function colorAt(GdImage $image, int $x, int $y): array
    {
        $color = imagecolorat($image, $x, $y);

        return [
            'red' => ($color >> 16) & 0xFF,
            'green' => ($color >> 8) & 0xFF,
            'blue' => $color & 0xFF,
            'alpha' => ($color >> 24) & 0x7F,
        ];
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}  $crop
     * @param  array{x: int, y: int, width: int, height: int}  $content
     */
    private function containsWithMargin(array $crop, array $content, int $margin): bool
    {
        return $content['x'] >= ($crop['x'] + $margin)
            && $content['y'] >= ($crop['y'] + $margin)
            && ($content['x'] + $content['width']) <= ($crop['x'] + $crop['width'] - $margin)
            && ($content['y'] + $content['height']) <= ($crop['y'] + $crop['height'] - $margin);
    }
}
