<?php

namespace App\Actions\Import;

use App\Import\AcquiredProductImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class AcquireRemoteProductImageAction
{
    public function execute(string $url): AcquiredProductImage
    {
        $this->assertHttpUrl($url);

        $tempPath = tempnam(sys_get_temp_dir(), 'gift-import-');

        if ($tempPath === false) {
            throw ValidationException::withMessages([
                'image' => ['The image could not be stored.'],
            ]);
        }

        try {
            $maxBytes = (int) config('media.product_images.max_upload_kilobytes') * 1024;
            $timeout = (int) config('import.http.timeout', 10);
            $connectTimeout = (int) config('import.http.connect_timeout', 5);
            $maxRedirects = (int) config('import.http.max_redirects', 3);

            $response = Http::timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withOptions([
                    'allow_redirects' => ['max' => $maxRedirects],
                    'sink' => $tempPath,
                    'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                        $length = $response->getHeaderLine('Content-Length');

                        if (is_numeric($length) && (int) $length > $maxBytes) {
                            throw ValidationException::withMessages([
                                'image' => ['The image exceeds the maximum upload size.'],
                            ]);
                        }
                    },
                ])
                ->get($url);

            if (! $response->successful()) {
                throw ValidationException::withMessages([
                    'image' => ['The image could not be downloaded.'],
                ]);
            }

            $declaredLength = $response->header('Content-Length');

            if (is_numeric($declaredLength) && (int) $declaredLength > $maxBytes) {
                throw ValidationException::withMessages([
                    'image' => ['The image exceeds the maximum upload size.'],
                ]);
            }

            if (! is_file($tempPath) || filesize($tempPath) === 0) {
                $body = $response->body();

                if ($body !== '') {
                    file_put_contents($tempPath, $body);
                }
            }

            $size = is_file($tempPath) ? filesize($tempPath) : false;

            if ($size === false || $size === 0) {
                throw ValidationException::withMessages([
                    'image' => ['The image could not be read.'],
                ]);
            }

            if ($size > $maxBytes) {
                throw ValidationException::withMessages([
                    'image' => ['The image exceeds the maximum upload size.'],
                ]);
            }

            $binary = file_get_contents($tempPath);

            if ($binary === false || $binary === '') {
                throw ValidationException::withMessages([
                    'image' => ['The image could not be read.'],
                ]);
            }

            $this->assertAllowedMime($binary);
            $this->assertValidImage($binary);

            return new AcquiredProductImage(
                path: $tempPath,
                contentHash: hash('sha256', $binary),
            );
        } catch (ConnectionException $exception) {
            $this->deleteTemp($tempPath);

            throw ValidationException::withMessages([
                'image' => ['The image download timed out.'],
            ]);
        } catch (Throwable $exception) {
            $this->deleteTemp($tempPath);

            throw $exception;
        }
    }

    private function assertHttpUrl(string $url): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages([
                'image' => ['The image URL is invalid.'],
            ]);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $parts['host'] ?? '';

        if ($host === '' || ($scheme !== 'http' && $scheme !== 'https')) {
            throw ValidationException::withMessages([
                'image' => ['The image URL is invalid.'],
            ]);
        }
    }

    private function assertAllowedMime(string $binary): void
    {
        $allowed = config('media.product_images.allowed_mime_types', []);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($binary);

        if (! is_string($mime) || ! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages([
                'image' => ['The image type is not allowed.'],
            ]);
        }
    }

    private function assertValidImage(string $binary): void
    {
        $info = getimagesizefromstring($binary);

        if ($info === false || ! isset($info[0], $info[1], $info['mime'])) {
            throw ValidationException::withMessages([
                'image' => ['The file is not a valid image.'],
            ]);
        }
    }

    private function deleteTemp(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
