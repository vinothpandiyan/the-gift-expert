<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

trait FakesCuratedProductImages
{
    use MakesRasterImages;

    /**
     * @return array<string, string>
     */
    protected function fakeCuratedAmazonImageHttp(?string $url = null): array
    {
        Storage::fake('public');

        $url ??= 'https://m.media-amazon.com/images/I/example.jpg';
        $body = (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg'));

        Http::fake([
            $url => Http::response($body, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        return [
            $url => hash('sha256', $body),
        ];
    }

    protected function curatedImageUrlForAsin(string $asin): string
    {
        return 'https://m.media-amazon.com/images/I/'.$asin.'.jpg';
    }
}
