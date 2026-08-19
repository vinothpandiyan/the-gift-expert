<?php

namespace App\CommercialSourcing;

use App\Enums\CommercialExternalIdSource;
use App\Support\CatalogCandidateSourceUrl;

class ExtractCommercialExternalProductId
{
    public function __construct(
        private CommercialSourcingMerchants $merchants,
    ) {}

    public function execute(string $slug, string $url): CommercialExternalIdentity
    {
        $config = $this->merchants->configForSlug($slug);

        if ($config === null) {
            return new CommercialExternalIdentity(null, CommercialExternalIdSource::None, false);
        }

        $normalized = CatalogCandidateSourceUrl::normalize($url) ?? $url;

        if ($this->isDenied($normalized, $config)) {
            return new CommercialExternalIdentity(null, CommercialExternalIdSource::None, true);
        }

        $strategy = (string) ($config['external_id_strategy'] ?? 'manual');

        if ($strategy === 'manual') {
            return new CommercialExternalIdentity(null, CommercialExternalIdSource::None, false);
        }

        if ($strategy === 'extractor') {
            $extracted = $this->extract($normalized, $config);

            if ($extracted !== null) {
                return new CommercialExternalIdentity($extracted, CommercialExternalIdSource::Extracted, false);
            }

            if ($this->fingerprintEnabled($config)) {
                return new CommercialExternalIdentity(
                    $this->fingerprint($normalized, $config, $slug),
                    CommercialExternalIdSource::UrlFingerprint,
                    false,
                );
            }

            return new CommercialExternalIdentity(null, CommercialExternalIdSource::None, false);
        }

        if ($strategy === 'url_fingerprint' && $this->fingerprintEnabled($config)) {
            return new CommercialExternalIdentity(
                $this->fingerprint($normalized, $config, $slug),
                CommercialExternalIdSource::UrlFingerprint,
                false,
            );
        }

        return new CommercialExternalIdentity(null, CommercialExternalIdSource::None, false);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function isDenied(string $url, array $config): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        foreach ($config['deny_path_patterns'] ?? [] as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            if (@preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function extract(string $url, array $config): ?string
    {
        $rules = $config['external_id']['rules'] ?? [];

        if (! is_array($rules)) {
            return null;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $type = $rule['type'] ?? null;

            if ($type === 'path_regex') {
                $pattern = $rule['pattern'] ?? null;

                if (! is_string($pattern) || $pattern === '') {
                    continue;
                }

                $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

                if (@preg_match($pattern, $path, $matches) === 1 && isset($matches[1]) && is_string($matches[1]) && $matches[1] !== '') {
                    return $matches[1];
                }
            }

            if ($type === 'query') {
                $param = $rule['param'] ?? null;

                if (! is_string($param) || $param === '') {
                    continue;
                }

                $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
                parse_str($query, $params);
                $value = $params[$param] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function fingerprintEnabled(array $config): bool
    {
        return ($config['url_fingerprint']['enabled'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function fingerprint(string $url, array $config, string $slug): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return 'url:'.hash('sha256', $slug.'|'.$url);
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $path = (string) ($parts['path'] ?? '');

        if ($path === '/') {
            $path = '';
        } elseif ($path !== '' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $query = [];

        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $parsed);

            $strip = [];

            foreach ($config['url_fingerprint']['strip_query_params'] ?? [] as $param) {
                if (is_string($param) && $param !== '') {
                    $strip[strtolower($param)] = true;
                }
            }

            foreach ($parsed as $key => $value) {
                if (! is_string($key) || isset($strip[strtolower($key)])) {
                    continue;
                }

                $query[$key] = $value;
            }

            ksort($query);
        }

        $identity = $scheme.'://'.$host.$path;

        if ($query !== []) {
            $identity .= '?'.http_build_query($query);
        }

        return 'url:'.hash('sha256', $slug.'|'.$identity);
    }
}
