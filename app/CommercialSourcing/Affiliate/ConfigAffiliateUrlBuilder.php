<?php

namespace App\CommercialSourcing\Affiliate;

use App\CommercialSourcing\CommercialSourcingMerchants;
use App\CommercialSourcing\SourcedMerchantOffer;
use App\Support\CatalogCandidateSourceUrl;

class ConfigAffiliateUrlBuilder implements AffiliateUrlBuilder
{
    public function __construct(
        private CommercialSourcingMerchants $merchants,
    ) {}

    /**
     * @param  array<string, mixed>  $merchantConfig
     */
    public function build(SourcedMerchantOffer $offer, array $merchantConfig): AffiliateUrlResult
    {
        $affiliate = is_array($merchantConfig['affiliate'] ?? null) ? $merchantConfig['affiliate'] : [];
        $strategy = trim((string) ($affiliate['strategy'] ?? $merchantConfig['affiliate_strategy'] ?? 'manual'));

        if ($strategy === '') {
            $strategy = 'manual';
        }

        return match ($strategy) {
            'query_param' => $this->queryParam($offer, $merchantConfig, $affiliate, $strategy),
            'template' => $this->template($offer, $merchantConfig, $affiliate, $strategy),
            'passthrough' => $this->passthrough($offer, $merchantConfig, $affiliate, $strategy),
            default => $this->manual($strategy),
        };
    }

    /**
     * @param  array<string, mixed>  $merchantConfig
     * @param  array<string, mixed>  $affiliate
     */
    private function queryParam(
        SourcedMerchantOffer $offer,
        array $merchantConfig,
        array $affiliate,
        string $strategy,
    ): AffiliateUrlResult {
        $param = $this->nonEmptyString($affiliate['param'] ?? null);
        $value = $this->nonEmptyString($affiliate['value'] ?? null);

        if ($param === null || $value === null) {
            return $this->manual($strategy);
        }

        $parts = parse_url($offer->sourceUrl);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $this->manual($strategy);
        }

        if (! $this->hostAllowed((string) $parts['host'], $merchantConfig, $affiliate)) {
            return $this->manual($strategy);
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $query[$param] = $value;
        $parts['query'] = http_build_query($query);

        $built = $this->assembleUrl($parts);
        $normalized = CatalogCandidateSourceUrl::normalize($built);

        if ($normalized === null || ! $this->urlHostAllowed($normalized, $merchantConfig, $affiliate)) {
            return $this->manual($strategy);
        }

        return new AffiliateUrlResult($normalized, true, $strategy, null);
    }

    /**
     * @param  array<string, mixed>  $merchantConfig
     * @param  array<string, mixed>  $affiliate
     */
    private function template(
        SourcedMerchantOffer $offer,
        array $merchantConfig,
        array $affiliate,
        string $strategy,
    ): AffiliateUrlResult {
        $template = $this->nonEmptyString($affiliate['template'] ?? null);

        if ($template === null) {
            return $this->manual($strategy);
        }

        if (str_contains($template, '{external_product_id}') && ($offer->externalProductId === null || $offer->externalProductId === '')) {
            return $this->manual($strategy);
        }

        $url = strtr($template, [
            '{url}' => $offer->sourceUrl,
            '{normalized_url}' => $offer->normalizedUrl,
            '{external_product_id}' => (string) $offer->externalProductId,
        ]);

        $normalized = CatalogCandidateSourceUrl::normalize($url);

        if ($normalized === null || ! $this->urlHostAllowed($normalized, $merchantConfig, $affiliate)) {
            return $this->manual($strategy);
        }

        return new AffiliateUrlResult($normalized, true, $strategy, null);
    }

    /**
     * @param  array<string, mixed>  $merchantConfig
     * @param  array<string, mixed>  $affiliate
     */
    private function passthrough(
        SourcedMerchantOffer $offer,
        array $merchantConfig,
        array $affiliate,
        string $strategy,
    ): AffiliateUrlResult {
        $normalized = CatalogCandidateSourceUrl::normalize($offer->sourceUrl);

        if ($normalized === null || ! $this->urlHostAllowed($normalized, $merchantConfig, $affiliate)) {
            return $this->manual($strategy);
        }

        return new AffiliateUrlResult($normalized, true, $strategy, null);
    }

    private function manual(string $strategy): AffiliateUrlResult
    {
        return new AffiliateUrlResult(null, false, $strategy, 'affiliate_manual');
    }

    /**
     * @param  array<string, mixed>  $merchantConfig
     * @param  array<string, mixed>  $affiliate
     */
    private function urlHostAllowed(string $url, array $merchantConfig, array $affiliate): bool
    {
        $host = $this->merchants->host($url);

        if ($host === null) {
            return false;
        }

        return $this->hostAllowed($host, $merchantConfig, $affiliate);
    }

    /**
     * @param  array<string, mixed>  $merchantConfig
     * @param  array<string, mixed>  $affiliate
     */
    private function hostAllowed(string $host, array $merchantConfig, array $affiliate): bool
    {
        $domains = [];

        foreach ($affiliate['allowed_domains'] ?? [] as $domain) {
            if (is_string($domain) && $this->merchants->normalizeDomain($domain) !== null) {
                $domains[] = $domain;
            }
        }

        if ($domains === []) {
            foreach ($merchantConfig['domains'] ?? [] as $domain) {
                if (is_string($domain) && $this->merchants->normalizeDomain($domain) !== null) {
                    $domains[] = $domain;
                }
            }
        }

        foreach ($domains as $domain) {
            if ($this->merchants->hostMatchesDomain($host, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function assembleUrl(array $parts): string
    {
        $scheme = (string) $parts['scheme'];
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $user = (string) ($parts['user'] ?? '');
        $pass = isset($parts['pass']) ? ':'.$parts['pass'] : '';
        $auth = $user !== '' ? $user.$pass.'@' : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#'.$parts['fragment'] : '';

        return $scheme.'://'.$auth.$host.$port.$path.$query.$fragment;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
