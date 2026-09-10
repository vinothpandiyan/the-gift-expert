<?php

namespace Tests\Unit\Actions\ProductImage;

use App\Actions\ProductImage\DetectSafeOuterBackgroundTrimAction;
use Tests\TestCase;

class DetectSafeOuterBackgroundTrimActionTest extends TestCase
{
    public function test_it_conservatively_plans_a_square_crop_for_uniform_outer_background(): void
    {
        $path = $this->imageWithCenteredProduct(1200, 500, [248, 246, 242]);

        $plan = app(DetectSafeOuterBackgroundTrimAction::class)->execute($path);

        $this->assertTrue($plan->shouldTrim);
        $this->assertSame('safe_uniform_outer_background', $plan->reason);
        $this->assertGreaterThanOrEqual(345, $plan->contentBox['x']);
        $this->assertLessThanOrEqual(350, $plan->contentBox['x']);
        $this->assertGreaterThanOrEqual(500, $plan->contentBox['width']);
        $this->assertLessThanOrEqual(506, $plan->contentBox['width']);
        $this->assertGreaterThanOrEqual(620, $plan->cropBox['width']);
        $this->assertLessThanOrEqual(630, $plan->cropBox['width']);
        $this->assertSame($plan->cropBox['width'], $plan->cropBox['height']);
        $this->assertEqualsWithDelta(0.42, $plan->occupancyBefore, 0.005);
        $this->assertEqualsWithDelta(0.81, $plan->occupancyAfter, 0.01);
    }

    public function test_it_preserves_a_uniform_non_white_background(): void
    {
        $path = $this->imageWithCenteredProduct(1200, 500, [210, 220, 228]);

        $plan = app(DetectSafeOuterBackgroundTrimAction::class)->execute($path);

        $this->assertFalse($plan->shouldTrim);
        $this->assertSame('background_not_confidently_artificial', $plan->reason);
    }

    public function test_it_preserves_a_photo_with_only_a_thin_uniform_outer_edge(): void
    {
        $image = imagecreatetruecolor(1200, 1200);
        $white = imagecolorallocate($image, 255, 255, 255);
        $photo = imagecolorallocate($image, 80, 120, 150);
        imagefilledrectangle($image, 0, 0, 1199, 1199, $white);
        imagefilledrectangle($image, 4, 4, 1195, 1195, $photo);
        $path = $this->writeImage($image);

        $plan = app(DetectSafeOuterBackgroundTrimAction::class)->execute($path);

        $this->assertFalse($plan->shouldTrim);
        $this->assertContains($plan->reason, [
            'background_not_confidently_artificial',
            'non_uniform_border',
        ]);
    }

    public function test_it_skips_images_with_non_uniform_edges(): void
    {
        $image = imagecreatetruecolor(1200, 1200);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 20, 20, 20);
        imagefilledrectangle($image, 0, 0, 1199, 1199, $white);
        imagefilledrectangle($image, 0, 0, 500, 1199, $black);
        $path = $this->writeImage($image);

        $plan = app(DetectSafeOuterBackgroundTrimAction::class)->execute($path);

        $this->assertFalse($plan->shouldTrim);
        $this->assertSame('non_uniform_corners', $plan->reason);
    }

    public function test_it_is_idempotent_for_an_already_normalized_canvas(): void
    {
        $path = $this->imageWithCenteredProduct(600, 500, [255, 255, 255]);

        $plan = app(DetectSafeOuterBackgroundTrimAction::class)->execute($path);

        $this->assertFalse($plan->shouldTrim);
        $this->assertSame('already_normalized', $plan->reason);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $background
     */
    private function imageWithCenteredProduct(int $canvas, int $product, array $background): string
    {
        $image = imagecreatetruecolor($canvas, $canvas);
        $backgroundColor = imagecolorallocate($image, ...$background);
        $productColor = imagecolorallocate($image, 30, 55, 80);
        imagefilledrectangle($image, 0, 0, $canvas - 1, $canvas - 1, $backgroundColor);
        $start = (int) (($canvas - $product) / 2);
        imagefilledrectangle($image, $start, $start, $start + $product - 1, $start + $product - 1, $productColor);

        return $this->writeImage($image);
    }

    private function writeImage(\GdImage $image): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gift-trim-test-').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
