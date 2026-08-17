<?php

namespace App\Import;

readonly class ImportedCatalogItem
{
    /**
     * @param  list<string>  $image_urls
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $name,
        public ?string $description,
        public ?string $short_description,
        public ?string $brand,
        public ?string $price_amount,
        public ?string $price_currency,
        public ?string $affiliate_url,
        public ?string $external_product_id,
        public array $image_urls,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        $imageUrls = [];

        if (isset($row['image_urls']) && is_array($row['image_urls'])) {
            foreach ($row['image_urls'] as $url) {
                if (is_string($url) && $url !== '') {
                    $imageUrls[] = $url;
                }
            }
        }

        return new self(
            name: self::nullableString($row['name'] ?? null),
            description: self::nullableString($row['description'] ?? null),
            short_description: self::nullableString($row['short_description'] ?? null),
            brand: self::nullableString($row['brand'] ?? null),
            price_amount: self::nullablePrice($row['price_amount'] ?? null),
            price_currency: self::nullableString($row['price_currency'] ?? null),
            affiliate_url: self::nullableString($row['affiliate_url'] ?? null),
            external_product_id: self::nullableString($row['external_product_id'] ?? null),
            image_urls: $imageUrls,
            raw: $row,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function nullablePrice(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        $string = self::nullableString($value);

        if ($string === null || ! is_numeric($string)) {
            return $string;
        }

        return number_format((float) $string, 2, '.', '');
    }
}
