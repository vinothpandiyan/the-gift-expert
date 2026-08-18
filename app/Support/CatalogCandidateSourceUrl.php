<?php

namespace App\Support;

use App\CatalogCandidate\Discovery\RetrievedCatalogCandidateSource;

final class CatalogCandidateSourceUrl
{
    public static function normalize(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    public static function key(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $normalized = self::normalize($url) ?? trim($url);

        if ($normalized === '') {
            return null;
        }

        $parts = parse_url($normalized);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if ($host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');

        if ($path === '/') {
            $path = '';
        } elseif ($path !== '' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }

    public static function sourceName(string $host): string
    {
        $host = strtolower($host);

        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        return $host;
    }

    /**
     * @param  list<RetrievedCatalogCandidateSource>  $corpus
     */
    public static function resolveAgainstCorpus(string $url, array $corpus): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        foreach ($corpus as $source) {
            if (trim($source->url) === $url) {
                return $source->url;
            }
        }

        $incomingKey = self::key($url);

        if ($incomingKey === null) {
            return null;
        }

        $matches = [];

        foreach ($corpus as $source) {
            if (self::key($source->url) !== $incomingKey) {
                continue;
            }

            $matches[$source->url] = $source->url;
        }

        if (count($matches) === 1) {
            return array_values($matches)[0];
        }

        return null;
    }

    /**
     * @param  list<RetrievedCatalogCandidateSource>  $corpus
     */
    public static function findInCorpus(string $url, array $corpus): ?RetrievedCatalogCandidateSource
    {
        $canonical = self::resolveAgainstCorpus($url, $corpus) ?? trim($url);

        foreach ($corpus as $source) {
            if ($source->url === $canonical) {
                return $source;
            }
        }

        return null;
    }
}
