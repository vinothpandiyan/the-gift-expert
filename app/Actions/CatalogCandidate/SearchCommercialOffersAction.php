<?php

namespace App\Actions\CatalogCandidate;

use App\CommercialSourcing\CommercialOfferSearchProvider;
use App\CommercialSourcing\CommercialSourcingMerchants;
use App\CommercialSourcing\ExtractCommercialExternalProductId;
use App\CommercialSourcing\ExtractCommercialOfferPrice;
use App\CommercialSourcing\SourcedMerchantOffer;
use App\Models\CatalogCandidate;
use App\Support\CatalogCandidateSourceUrl;
use InvalidArgumentException;

class SearchCommercialOffersAction
{
    public function __construct(
        private CommercialSourcingMerchants $merchants,
        private ExtractCommercialExternalProductId $extractExternalProductId,
        private ExtractCommercialOfferPrice $extractPrice,
    ) {}

    /**
     * @return array{offers: list<SourcedMerchantOffer>, queries: list<string>, metadata: array<string, mixed>}
     */
    public function execute(CatalogCandidate $candidate, string $market): array
    {
        $result = $this->resolveProvider()->search($candidate, $market);
        $offers = [];
        $seen = [];

        foreach ($result->hits as $hit) {
            $merchant = $this->merchants->resolveFromUrl($hit->url, $market);

            if ($merchant === null) {
                continue;
            }

            $normalized = CatalogCandidateSourceUrl::key($hit->url) ?? $hit->url;
            $identity = $this->extractExternalProductId->execute($merchant->slug, $hit->url);
            $dedupeKey = $merchant->id.'|'.($identity->externalProductId ?? $normalized);

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $price = $this->extractPrice->execute($hit->snippet);
            $snippetMax = max(1, (int) config('commercial_sourcing.search.snippet_max_length', 400));
            $snippet = mb_strlen($hit->snippet) > $snippetMax
                ? mb_substr($hit->snippet, 0, $snippetMax)
                : $hit->snippet;

            $offers[] = new SourcedMerchantOffer(
                merchantId: $merchant->id,
                merchantSlug: $merchant->slug,
                sourceUrl: $hit->url,
                normalizedUrl: $normalized,
                title: $hit->title,
                snippet: $snippet,
                externalProductId: $identity->externalProductId,
                externalIdSource: $identity->source,
                priceAmount: $price['amount'] ?? null,
                priceCurrency: $price['currency'] ?? null,
                imageUrls: $hit->imageUrls,
                retrievedAt: $hit->retrievedAt,
                sourceEvidence: [[
                    'url' => $hit->url,
                    'title' => $hit->title,
                    'snippet' => $snippet,
                ]],
                rankScore: 0,
            );
        }

        return [
            'offers' => $offers,
            'queries' => $result->queries,
            'metadata' => $result->metadata,
        ];
    }

    public function resolveProvider(?string $providerKey = null): CommercialOfferSearchProvider
    {
        $providerKey ??= $this->providerKey();
        $config = config('commercial_sourcing.search.providers.'.$providerKey);

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown commercial sourcing search provider [{$providerKey}].");
        }

        $allowedEnvironments = $config['allowed_environments'] ?? null;

        if (is_array($allowedEnvironments) && $allowedEnvironments !== [] && ! app()->environment($allowedEnvironments)) {
            throw new InvalidArgumentException(
                "The [{$providerKey}] commercial sourcing search provider is not permitted in this environment.",
            );
        }

        $class = $config['class'] ?? null;

        if (! is_string($class) || $class === '' || ! is_a($class, CommercialOfferSearchProvider::class, true)) {
            throw new InvalidArgumentException("Commercial sourcing search provider [{$providerKey}] is not configured.");
        }

        $provider = app($class);

        if (! $provider instanceof CommercialOfferSearchProvider) {
            throw new InvalidArgumentException("Commercial sourcing search provider [{$providerKey}] is not configured.");
        }

        return $provider;
    }

    public function providerKey(): string
    {
        $providerKey = config('commercial_sourcing.search.provider');

        if (! is_string($providerKey) || trim($providerKey) === '') {
            throw new InvalidArgumentException('A commercial sourcing search provider is not configured.');
        }

        return trim($providerKey);
    }
}
