<?php

namespace App\CommercialSourcing;

use App\Models\Merchant;
use App\Support\CatalogCandidateSourceUrl;
use Illuminate\Support\Collection;

class CommercialSourcingMerchants
{
    /**
     * @return list<string>
     */
    public function includeDomains(string $market): array
    {
        $domains = [];

        foreach ($this->searchableConfigs($market) as $config) {
            foreach ($config['domains'] as $domain) {
                $normalized = $this->normalizeDomain($domain);

                if ($normalized !== null) {
                    $domains[$normalized] = $normalized;
                }
            }
        }

        $list = array_values($domains);
        sort($list);

        return $list;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function configForSlug(string $slug): ?array
    {
        $config = config('commercial_sourcing.merchants.'.$slug);

        if (! is_array($config) || ($config['enabled'] ?? false) !== true) {
            return null;
        }

        return $config;
    }

    public function resolveFromUrl(string $url, string $market): ?Merchant
    {
        $host = $this->host($url);

        if ($host === null) {
            return null;
        }

        $matchedSlug = null;

        foreach ($this->searchableConfigs($market) as $slug => $config) {
            foreach ($config['domains'] as $domain) {
                if ($this->hostMatchesDomain($host, $domain)) {
                    $matchedSlug = $slug;

                    break 2;
                }
            }
        }

        if ($matchedSlug === null) {
            return null;
        }

        $merchant = Merchant::query()
            ->where('slug', $matchedSlug)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $merchant instanceof Merchant) {
            return null;
        }

        return $merchant;
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    public function searchableConfigs(string $market): Collection
    {
        $market = strtoupper(trim($market));
        $entries = config('commercial_sourcing.merchants', []);

        if (! is_array($entries)) {
            return collect();
        }

        $matched = [];

        foreach ($entries as $slug => $config) {
            if (! is_string($slug) || $slug === '' || ! is_array($config)) {
                continue;
            }

            if (($config['enabled'] ?? false) !== true) {
                continue;
            }

            if (($config['search_enabled'] ?? false) !== true) {
                continue;
            }

            $markets = $config['markets'] ?? [];

            if (! is_array($markets) || ! in_array($market, $markets, true)) {
                continue;
            }

            $domains = [];

            foreach ($config['domains'] ?? [] as $domain) {
                if (is_string($domain) && $this->normalizeDomain($domain) !== null) {
                    $domains[] = $domain;
                }
            }

            if ($domains === []) {
                continue;
            }

            $config['domains'] = $domains;
            $matched[$slug] = $config;
        }

        return collect($matched);
    }

    public function hostMatchesDomain(string $host, string $domain): bool
    {
        $host = $this->normalizeDomain($host);
        $domain = $this->normalizeDomain($domain);

        if ($host === null || $domain === null) {
            return false;
        }

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    public function normalizeDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));

        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        if ($domain === '' || str_contains($domain, '/') || str_contains($domain, ':')) {
            return null;
        }

        return $domain;
    }

    public function host(string $url): ?string
    {
        $normalized = CatalogCandidateSourceUrl::normalize($url);

        if ($normalized === null) {
            return null;
        }

        $host = parse_url($normalized, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return $this->normalizeDomain($host);
    }
}
