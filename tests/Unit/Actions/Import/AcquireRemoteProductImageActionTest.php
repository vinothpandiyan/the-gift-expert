<?php

namespace Tests\Unit\Actions\Import;

use App\Actions\Import\AcquireRemoteProductImageAction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\MakesRasterImages;
use Tests\TestCase;

class AcquireRemoteProductImageActionTest extends TestCase
{
    use MakesRasterImages;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_downloads_a_valid_image_and_hashes_original_bytes(): void
    {
        $body = (string) file_get_contents($this->rasterImagePath(640, 480, 'jpeg'));

        Http::fake([
            'https://example.test/image.jpg' => Http::response($body, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $acquired = app(AcquireRemoteProductImageAction::class)->execute('https://example.test/image.jpg');

        $this->assertFileExists($acquired->path);
        $this->assertSame(hash('sha256', $body), $acquired->contentHash);
        $this->assertSame($body, file_get_contents($acquired->path));

        unlink($acquired->path);
    }

    public function test_it_rejects_file_urls_and_does_not_leave_temp_files(): void
    {
        $before = $this->importTempFiles();

        try {
            app(AcquireRemoteProductImageAction::class)->execute('file:///tmp/secret.jpg');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->importTempFiles());
        }
    }

    public function test_it_rejects_malformed_urls(): void
    {
        $before = $this->importTempFiles();

        try {
            app(AcquireRemoteProductImageAction::class)->execute('not-a-url');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->importTempFiles());
        }
    }

    public function test_it_rejects_oversized_content_length(): void
    {
        $body = (string) file_get_contents($this->rasterImagePath(200, 200, 'jpeg'));
        $before = $this->importTempFiles();

        Http::fake([
            'https://example.test/huge.jpg' => Http::response($body, 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => (string) (9 * 1024 * 1024),
            ]),
        ]);

        try {
            app(AcquireRemoteProductImageAction::class)->execute('https://example.test/huge.jpg');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertContains('The image exceeds the maximum upload size.', $exception->errors()['image']);
            $this->assertSame($before, $this->importTempFiles());
        }
    }

    public function test_it_rejects_oversized_downloaded_files(): void
    {
        $body = (string) file_get_contents($this->rasterImagePath(400, 400, 'jpeg'));
        $before = $this->importTempFiles();

        config()->set('media.product_images.max_upload_kilobytes', 1);

        Http::fake([
            'https://example.test/big.jpg' => Http::response($body, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        try {
            app(AcquireRemoteProductImageAction::class)->execute('https://example.test/big.jpg');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertContains('The image exceeds the maximum upload size.', $exception->errors()['image']);
            $this->assertSame($before, $this->importTempFiles());
        }
    }

    public function test_it_rejects_empty_files(): void
    {
        $before = $this->importTempFiles();

        Http::fake([
            'https://example.test/empty.jpg' => Http::response('', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        try {
            app(AcquireRemoteProductImageAction::class)->execute('https://example.test/empty.jpg');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->importTempFiles());
        }
    }

    public function test_it_rejects_non_image_bytes(): void
    {
        $before = $this->importTempFiles();

        Http::fake([
            'https://example.test/note.txt' => Http::response('not an image', 200, ['Content-Type' => 'text/plain']),
        ]);

        try {
            app(AcquireRemoteProductImageAction::class)->execute('https://example.test/note.txt');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->importTempFiles());
        }
    }

    public function test_it_rejects_http_404(): void
    {
        $before = $this->importTempFiles();

        Http::fake([
            'https://example.test/missing.jpg' => Http::response('missing', 404),
        ]);

        try {
            app(AcquireRemoteProductImageAction::class)->execute('https://example.test/missing.jpg');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertContains('The image could not be downloaded.', $exception->errors()['image']);
            $this->assertSame($before, $this->importTempFiles());
        }
    }

    public function test_it_rejects_timeouts_and_cleans_up_temp_files(): void
    {
        $before = $this->importTempFiles();

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        try {
            app(AcquireRemoteProductImageAction::class)->execute('https://example.test/slow.jpg');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertContains('The image download timed out.', $exception->errors()['image']);
            $this->assertSame($before, $this->importTempFiles());
        }
    }

    /**
     * @return list<string>
     */
    private function importTempFiles(): array
    {
        $files = glob(sys_get_temp_dir().'/gift-import-*') ?: [];
        sort($files);

        return $files;
    }
}
