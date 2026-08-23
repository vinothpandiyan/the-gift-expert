<?php

namespace App\Actions\CuratedCatalog;

use Illuminate\Validation\ValidationException;

class ValidateCuratedProductImageUrlAction
{
    /**
     * @param  list<string>  $allowedHosts
     */
    public function execute(string $url, array $allowedHosts, bool $httpsOnly = true): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages([
                'image' => ['The image URL is invalid.'],
            ]);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            throw ValidationException::withMessages([
                'image' => ['The image URL is invalid.'],
            ]);
        }

        if ($httpsOnly && $scheme !== 'https') {
            throw ValidationException::withMessages([
                'image' => ['The image URL must use HTTPS.'],
            ]);
        }

        if (! $httpsOnly && $scheme !== 'http' && $scheme !== 'https') {
            throw ValidationException::withMessages([
                'image' => ['The image URL is invalid.'],
            ]);
        }

        $normalizedAllowed = array_map(strtolower(...), $allowedHosts);

        if ($normalizedAllowed === [] || ! in_array($host, $normalizedAllowed, true)) {
            throw ValidationException::withMessages([
                'image' => ['The image host is not allowed.'],
            ]);
        }

        $this->assertHostDoesNotResolveToPrivateAddress($host);
    }

    private function assertHostDoesNotResolveToPrivateAddress(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublicIp($host);

            return;
        }

        if (in_array($host, ['localhost', 'metadata.google.internal'], true)) {
            throw ValidationException::withMessages([
                'image' => ['The image host is not allowed.'],
            ]);
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false || $records === []) {
            $resolved = @gethostbyname($host);

            if ($resolved === $host) {
                throw ValidationException::withMessages([
                    'image' => ['The image host could not be resolved.'],
                ]);
            }

            $this->assertPublicIp($resolved);

            return;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && $ip !== '') {
                $this->assertPublicIp($ip);
            }
        }
    }

    private function assertPublicIp(string $ip): void
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw ValidationException::withMessages([
                'image' => ['The image host is not allowed.'],
            ]);
        }
    }
}
