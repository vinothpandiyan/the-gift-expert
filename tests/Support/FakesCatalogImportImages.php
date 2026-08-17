<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

trait FakesCatalogImportImages
{
    use MakesRasterImages;

    /**
     * @return array<string, string>
     */
    protected function fakeCatalogImageHttp(): array
    {
        Storage::fake('public');
        Http::preventStrayRequests();

        $bodies = [
            'https://example.test/images/wallet-1.jpg' => (string) file_get_contents($this->rasterImagePath(800, 600, 'jpeg')),
            'https://example.test/images/wallet-2.jpg' => (string) file_get_contents($this->rasterImagePath(900, 700, 'jpeg')),
            'https://example.test/images/coffee-kit.jpg' => (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg')),
            'https://example.test/images/wallet-updated.jpg' => (string) file_get_contents($this->rasterImagePath(820, 620, 'jpeg')),
            'https://example.test/images/coffee-kit-updated.jpg' => (string) file_get_contents($this->rasterImagePath(650, 650, 'jpeg')),
            'https://example.test/images/missing-id.jpg' => (string) file_get_contents($this->rasterImagePath(400, 400, 'jpeg')),
        ];

        $fakes = [];
        $hashes = [];

        foreach ($bodies as $url => $body) {
            $fakes[$url] = Http::response($body, 200, ['Content-Type' => 'image/jpeg']);
            $hashes[$url] = hash('sha256', $body);
        }

        Http::fake($fakes);

        return $hashes;
    }
}
