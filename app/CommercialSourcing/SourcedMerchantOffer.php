<?php

namespace App\CommercialSourcing;

use App\Enums\CommercialExternalIdSource;

readonly class SourcedMerchantOffer
{
    /**
     * @param  list<string>  $imageUrls
     * @param  list<array{url: string, title: string, snippet: string}>  $sourceEvidence
     */
    public function __construct(
        public int $merchantId,
        public string $merchantSlug,
        public string $sourceUrl,
        public string $normalizedUrl,
        public string $title,
        public string $snippet,
        public ?string $externalProductId,
        public CommercialExternalIdSource $externalIdSource,
        public ?string $priceAmount,
        public ?string $priceCurrency,
        public array $imageUrls,
        public mixed $retrievedAt,
        public array $sourceEvidence,
        public int $rankScore,
    ) {}

    /**
     * @param  array<string, int>  $rankBreakdown
     * @return array<string, mixed>
     */
    public function toAuditArray(array $rankBreakdown = []): array
    {
        $payload = [
            'merchant_id' => $this->merchantId,
            'merchant_slug' => $this->merchantSlug,
            'source_url' => $this->sourceUrl,
            'normalized_url' => $this->normalizedUrl,
            'title' => $this->title,
            'snippet' => $this->snippet,
            'external_product_id' => $this->externalProductId,
            'external_id_source' => $this->externalIdSource->value,
            'price_amount' => $this->priceAmount,
            'price_currency' => $this->priceCurrency,
            'image_urls' => $this->imageUrls,
            'retrieved_at' => $this->retrievedAt instanceof \DateTimeInterface
                ? $this->retrievedAt->format('c')
                : $this->retrievedAt,
            'source_evidence' => $this->sourceEvidence,
            'rank_score' => $this->rankScore,
        ];

        if ($rankBreakdown !== []) {
            $payload['rank_breakdown'] = $rankBreakdown;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromAuditArray(array $payload): self
    {
        $imageUrls = [];

        foreach ($payload['image_urls'] ?? [] as $url) {
            if (is_string($url) && $url !== '') {
                $imageUrls[] = $url;
            }
        }

        $sourceEvidence = [];

        foreach ($payload['source_evidence'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $sourceEvidence[] = [
                'url' => is_string($row['url'] ?? null) ? $row['url'] : '',
                'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
                'snippet' => is_string($row['snippet'] ?? null) ? $row['snippet'] : '',
            ];
        }

        $source = CommercialExternalIdSource::tryFrom((string) ($payload['external_id_source'] ?? ''))
            ?? CommercialExternalIdSource::None;

        return new self(
            merchantId: (int) ($payload['merchant_id'] ?? 0),
            merchantSlug: (string) ($payload['merchant_slug'] ?? ''),
            sourceUrl: (string) ($payload['source_url'] ?? ''),
            normalizedUrl: (string) ($payload['normalized_url'] ?? ''),
            title: (string) ($payload['title'] ?? ''),
            snippet: (string) ($payload['snippet'] ?? ''),
            externalProductId: is_string($payload['external_product_id'] ?? null) ? $payload['external_product_id'] : null,
            externalIdSource: $source,
            priceAmount: is_string($payload['price_amount'] ?? null) ? $payload['price_amount'] : null,
            priceCurrency: is_string($payload['price_currency'] ?? null) ? $payload['price_currency'] : null,
            imageUrls: $imageUrls,
            retrievedAt: $payload['retrieved_at'] ?? null,
            sourceEvidence: $sourceEvidence,
            rankScore: (int) ($payload['rank_score'] ?? 0),
        );
    }

    public function withRankScore(int $rankScore): self
    {
        return new self(
            merchantId: $this->merchantId,
            merchantSlug: $this->merchantSlug,
            sourceUrl: $this->sourceUrl,
            normalizedUrl: $this->normalizedUrl,
            title: $this->title,
            snippet: $this->snippet,
            externalProductId: $this->externalProductId,
            externalIdSource: $this->externalIdSource,
            priceAmount: $this->priceAmount,
            priceCurrency: $this->priceCurrency,
            imageUrls: $this->imageUrls,
            retrievedAt: $this->retrievedAt,
            sourceEvidence: $this->sourceEvidence,
            rankScore: $rankScore,
        );
    }
}
