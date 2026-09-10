<?php

namespace App\Actions\ProductImage;

use App\Models\Merchant;

class NormalizeAmazonProductImageUrlAction
{
    public function execute(string $url, ?Merchant $merchant = null): NormalizedAmazonProductImageUrl
    {
        $original = trim($url);

        if ($original === '' || ($merchant !== null && ! $this->isAmazonMerchant($merchant))) {
            return new NormalizedAmazonProductImageUrl(
                url: $original,
                isAmazon: false,
                changed: false,
                detectedLongEdge: null,
                requestedLongEdge: null,
            );
        }

        $parts = parse_url($original);

        if (! is_array($parts)) {
            return $this->unmodified($original);
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($host === '' || $path === '' || ($scheme !== 'http' && $scheme !== 'https')) {
            return $this->unmodified($original);
        }

        if (! $this->isAmazonImageHost($host)) {
            return $this->unmodified($original);
        }

        if (! preg_match(
            '#/images/I/([^/.]+)(?:\._([A-Za-z0-9,_]+))?(\.[A-Za-z0-9]+)$#',
            $path,
            $matches,
        )) {
            return $this->unmodified($original);
        }

        $imageId = rawurldecode($matches[1]);
        $modifier = $matches[2] ?? '';
        $extension = strtolower($matches[3]);

        if ($imageId === '' || ! preg_match('/^[A-Za-z0-9+_-]+$/', $imageId)) {
            return $this->unmodified($original);
        }

        $detected = $this->detectLongEdge($modifier);
        $family = $this->detectFamily($modifier);
        $token = $this->requestedToken($family, $detected);
        $canonicalHost = $this->canonicalHost();
        $canonical = 'https://'.$canonicalHost.'/images/I/'.$imageId.'._'.$token.'_'.$extension;

        return new NormalizedAmazonProductImageUrl(
            url: $canonical,
            isAmazon: true,
            changed: $canonical !== $original,
            detectedLongEdge: $detected,
            requestedLongEdge: $this->edgeFromToken($token),
        );
    }

    public function isAmazonMerchant(?Merchant $merchant): bool
    {
        if ($merchant === null) {
            return false;
        }

        return str_starts_with((string) $merchant->slug, 'amazon-')
            || $merchant->affiliate_network === 'amazon_associates';
    }

    private function unmodified(string $url): NormalizedAmazonProductImageUrl
    {
        return new NormalizedAmazonProductImageUrl(
            url: $url,
            isAmazon: false,
            changed: false,
            detectedLongEdge: null,
            requestedLongEdge: null,
        );
    }

    private function isAmazonImageHost(string $host): bool
    {
        foreach ($this->amazonHosts() as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return str_ends_with($host, '.ssl-images-amazon.com')
            || str_ends_with($host, '.media-amazon.com');
    }

    /**
     * @return list<string>
     */
    private function amazonHosts(): array
    {
        $hosts = config('curated_catalog.image_acquisition.amazon.hosts', []);

        if (! is_array($hosts)) {
            return ['m.media-amazon.com'];
        }

        return array_values(array_filter($hosts, is_string(...)));
    }

    private function canonicalHost(): string
    {
        $host = config('curated_catalog.image_acquisition.amazon.canonical_host', 'm.media-amazon.com');

        return is_string($host) && $host !== '' ? $host : 'm.media-amazon.com';
    }

    private function detectLongEdge(string $modifier): ?int
    {
        if ($modifier === '') {
            return null;
        }

        if (! preg_match_all('/(?:SS|SX|SY|SL|UX|UY|UL|US)(\d{2,4})/i', $modifier, $matches)) {
            return null;
        }

        $sizes = array_map(intval(...), $matches[1]);

        return $sizes === [] ? null : max($sizes);
    }

    private function detectFamily(string $modifier): ?string
    {
        if ($modifier === '') {
            return null;
        }

        if (! preg_match('/(SS|SX|SY|SL|UX|UY|UL|US)(\d{2,4})/i', $modifier, $matches)) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    private function requestedToken(?string $family, ?int $detected): string
    {
        $canonicalToken = strtoupper((string) config('curated_catalog.image_acquisition.amazon.canonical_token', 'SS'));
        if ($canonicalToken === '') {
            $canonicalToken = 'SS';
        }

        $minimum = max(1, (int) config('curated_catalog.image_acquisition.amazon.min_acceptable_long_edge', 1000));
        $keepFamilies = ['SL', 'SX', 'SY', 'UL', 'UX', 'UY'];
        $keepFamily = is_string($family) && in_array($family, $keepFamilies, true);
        $edge = $this->requestedLongEdge($keepFamily ? $detected : null);
        $token = $canonicalToken;

        if ($keepFamily && $detected !== null && $detected >= $minimum) {
            $token = $family;
        }

        return $token.$edge;
    }

    private function edgeFromToken(string $token): int
    {
        if (! preg_match('/(\d+)$/', $token, $matches)) {
            return 0;
        }

        return (int) $matches[1];
    }

    private function requestedLongEdge(?int $detected): int
    {
        $canonical = max(1, (int) config('curated_catalog.image_acquisition.amazon.canonical_long_edge', 1200));
        $minimum = max(1, (int) config('curated_catalog.image_acquisition.amazon.min_acceptable_long_edge', 1000));
        $maximum = max($canonical, (int) config('curated_catalog.image_acquisition.amazon.max_long_edge', 1500));

        if ($detected !== null && $detected >= $minimum) {
            return min($detected, $maximum);
        }

        return min($canonical, $maximum);
    }
}
