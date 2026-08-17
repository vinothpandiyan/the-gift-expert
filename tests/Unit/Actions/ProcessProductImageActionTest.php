<?php

namespace Tests\Unit\Actions;

use App\Actions\ProductImage\ProcessProductImageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Tests\Support\MakesRasterImages;
use Tests\TestCase;

class ProcessProductImageActionTest extends TestCase
{
    use MakesRasterImages;
    use RefreshDatabase;

    public function test_it_center_crops_and_encodes_webp_without_upscaling(): void
    {
        $path = $this->rasterImagePath(1200, 800);

        $processed = app(ProcessProductImageAction::class)->execute($path);

        $this->assertSame('image/webp', $processed->mimeType);
        $this->assertSame('webp', $processed->extension);
        $this->assertSame(800, $processed->width);
        $this->assertSame(800, $processed->height);
        $this->assertSame($processed->width, $processed->height);
        $this->assertGreaterThan(0, strlen($processed->contents));
        $this->assertLessThan(filesize($path), strlen($processed->contents));

        $decoded = imagecreatefromstring($processed->contents);
        $this->assertNotFalse($decoded);
        $this->assertSame(800, imagesx($decoded));
        $this->assertSame(800, imagesy($decoded));
        imagedestroy($decoded);
    }

    public function test_square_source_is_not_upscaled(): void
    {
        $path = $this->rasterImagePath(1200, 1200);

        $processed = app(ProcessProductImageAction::class)->execute($path);

        $this->assertSame(1200, $processed->width);
        $this->assertSame(1200, $processed->height);
        $this->assertSame('image/webp', $processed->mimeType);
    }

    public function test_oversized_square_source_is_resized_to_canonical_dimensions(): void
    {
        $path = $this->rasterImagePath(2000, 2000);

        $processed = app(ProcessProductImageAction::class)->execute($path);

        $this->assertSame((int) config('media.product_images.canonical_width'), $processed->width);
        $this->assertSame((int) config('media.product_images.canonical_height'), $processed->height);
    }

    public function test_it_rejects_invalid_image_content(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gift-invalid-');
        file_put_contents($path, 'not-an-image');

        $this->expectException(ValidationException::class);

        app(ProcessProductImageAction::class)->execute($path);
    }

    public function test_it_rejects_oversized_uploads(): void
    {
        Config::set('media.product_images.max_upload_kilobytes', 1);
        $path = $this->rasterImagePath(800, 800);

        $this->expectException(ValidationException::class);

        app(ProcessProductImageAction::class)->execute($path);
    }
}
