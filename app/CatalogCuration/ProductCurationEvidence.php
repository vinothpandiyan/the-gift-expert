<?php

namespace App\CatalogCuration;

readonly class ProductCurationEvidence
{
    /**
     * @param  array<string, list<array{id: int, name: string, slug: string}>>  $taxonomy
     * @param  list<array<string, mixed>>  $offers
     * @param  list<array<string, mixed>>  $images
     * @param  list<array<string, mixed>>  $provenance
     */
    public function __construct(
        public int $productId,
        public string $name,
        public ?string $shortDescription,
        public ?string $description,
        public ?string $brand,
        public string $status,
        public ?string $priceAmount,
        public string $priceCurrency,
        public ?string $compareAtAmount,
        public ?float $rating,
        public ?int $reviewCount,
        public array $taxonomy,
        public array $offers,
        public array $images,
        public array $provenance,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'name' => $this->name,
            'short_description' => $this->shortDescription,
            'description' => $this->description,
            'brand' => $this->brand,
            'status' => $this->status,
            'price' => [
                'amount' => $this->priceAmount,
                'currency' => $this->priceCurrency,
                'compare_at_amount' => $this->compareAtAmount,
            ],
            'rating' => $this->rating,
            'review_count' => $this->reviewCount,
            'taxonomy' => $this->taxonomy,
            'offers' => $this->offers,
            'images' => $this->images,
            'provenance' => $this->provenance,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        return new self(
            productId: (int) $snapshot['product_id'],
            name: (string) $snapshot['name'],
            shortDescription: $snapshot['short_description'] ?? null,
            description: $snapshot['description'] ?? null,
            brand: $snapshot['brand'] ?? null,
            status: (string) $snapshot['status'],
            priceAmount: $snapshot['price']['amount'] ?? null,
            priceCurrency: (string) ($snapshot['price']['currency'] ?? 'INR'),
            compareAtAmount: $snapshot['price']['compare_at_amount'] ?? null,
            rating: isset($snapshot['rating']) ? (float) $snapshot['rating'] : null,
            reviewCount: isset($snapshot['review_count']) ? (int) $snapshot['review_count'] : null,
            taxonomy: is_array($snapshot['taxonomy'] ?? null) ? $snapshot['taxonomy'] : [],
            offers: is_array($snapshot['offers'] ?? null) ? $snapshot['offers'] : [],
            images: is_array($snapshot['images'] ?? null) ? $snapshot['images'] : [],
            provenance: is_array($snapshot['provenance'] ?? null) ? $snapshot['provenance'] : [],
        );
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
