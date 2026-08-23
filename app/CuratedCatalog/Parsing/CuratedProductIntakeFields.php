<?php

namespace App\CuratedCatalog\Parsing;

use App\CommercialSourcing\CommercialSourcingMerchants;
use App\CommercialSourcing\ExtractCommercialExternalProductId;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\CuratedCatalog\CuratedProductInputError;
use App\Support\CatalogCandidateSourceUrl;

class CuratedProductIntakeFields
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function unknownKeys(array $payload, array $allowed): array
    {
        $unknown = [];

        foreach (array_keys($payload) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                $unknown[] = (string) $key;
            }
        }

        sort($unknown);

        return $unknown;
    }

    public static function assertUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function itemFromRow(
        int $itemIndex,
        string $merchantSlug,
        array $row,
        ?string $rootCapturedAt,
        ?string $rootCurationGroup,
        ?string $formCurationGroup,
        CommercialSourcingMerchants $merchants,
        ExtractCommercialExternalProductId $extractExternalId,
    ): CuratedMerchantProductInput|CuratedProductInputError {
        $allowedItemKeys = [
            'external_product_id',
            'source_url',
            'title',
            'price_amount',
            'price_currency',
            'source_image_url',
            'availability',
        ];

        if (array_is_list($row)) {
            return new CuratedProductInputError($itemIndex, 'invalid_item', 'Each item must be an object.');
        }

        $unknown = self::unknownKeys($row, $allowedItemKeys);

        if ($unknown !== []) {
            return new CuratedProductInputError(
                $itemIndex,
                'unknown_item_keys',
                'Unknown item fields are not allowed: '.implode(', ', $unknown).'.',
            );
        }

        if (array_key_exists('merchant', $row)) {
            return new CuratedProductInputError(
                $itemIndex,
                'item_merchant_not_allowed',
                'Per-item merchant is not allowed. Use the root merchant field.',
            );
        }

        $merchantConfig = config('curated_catalog.merchants.'.$merchantSlug);

        if (! is_array($merchantConfig) || ($merchantConfig['enabled'] ?? false) !== true) {
            return new CuratedProductInputError($itemIndex, 'unsupported_merchant', 'The merchant is not enabled for curated intake.');
        }

        $commercialConfig = $merchants->configForSlug($merchantSlug);

        if ($commercialConfig === null) {
            return new CuratedProductInputError($itemIndex, 'unsupported_merchant', 'The merchant is not configured for commercial sourcing.');
        }

        $externalProductId = self::nullableString($row['external_product_id'] ?? null);

        if ($externalProductId === null) {
            return new CuratedProductInputError($itemIndex, 'missing_external_product_id', 'external_product_id is required.');
        }

        $externalProductId = strtoupper($externalProductId);
        $asinPattern = (string) ($merchantConfig['asin_pattern'] ?? '/^[A-Z0-9]{10}$/i');

        if (@preg_match($asinPattern, $externalProductId) !== 1) {
            return new CuratedProductInputError($itemIndex, 'invalid_asin', 'external_product_id is not a valid ASIN.');
        }

        $sourceUrl = self::nullableString($row['source_url'] ?? null);

        if ($sourceUrl === null) {
            return new CuratedProductInputError($itemIndex, 'missing_source_url', 'source_url is required.');
        }

        $normalizedUrl = CatalogCandidateSourceUrl::normalize($sourceUrl);

        if ($normalizedUrl === null || ! filter_var($normalizedUrl, FILTER_VALIDATE_URL)) {
            return new CuratedProductInputError($itemIndex, 'invalid_source_url', 'source_url must be a valid HTTPS URL.');
        }

        if (! str_starts_with(strtolower($normalizedUrl), 'https://')) {
            return new CuratedProductInputError($itemIndex, 'invalid_source_url', 'source_url must use HTTPS.');
        }

        $host = $merchants->host($normalizedUrl);

        if ($host === null || ! self::hostAllowed($host, $commercialConfig)) {
            return new CuratedProductInputError($itemIndex, 'invalid_host', 'source_url host is not allowed for this merchant.');
        }

        $extracted = $extractExternalId->execute($merchantSlug, $normalizedUrl);

        if ($extracted->unstableIdentity) {
            return new CuratedProductInputError($itemIndex, 'denied_source_url', 'source_url is not a stable product identity URL.');
        }

        if ($extracted->externalProductId === null) {
            return new CuratedProductInputError($itemIndex, 'invalid_source_url', 'source_url does not resolve to a product identity.');
        }

        if (strtoupper($extracted->externalProductId) !== $externalProductId) {
            return new CuratedProductInputError(
                $itemIndex,
                'asin_url_mismatch',
                'external_product_id does not match the product URL.',
            );
        }

        $title = self::nullableString($row['title'] ?? null);

        if ($title === null) {
            return new CuratedProductInputError($itemIndex, 'missing_title', 'title is required.');
        }

        $defaultCurrency = (string) ($merchantConfig['default_currency'] ?? 'INR');
        $priceAmount = null;
        $priceCurrency = null;

        if (array_key_exists('price_amount', $row) && $row['price_amount'] !== null && $row['price_amount'] !== '') {
            $normalizedPrice = self::nullablePrice($row['price_amount']);

            if ($normalizedPrice === null || (float) $normalizedPrice < 0) {
                return new CuratedProductInputError($itemIndex, 'invalid_price', 'price_amount is malformed.');
            }

            $priceAmount = $normalizedPrice;
            $priceCurrency = self::nullableString($row['price_currency'] ?? null) ?? $defaultCurrency;

            if ($priceCurrency !== $defaultCurrency) {
                return new CuratedProductInputError(
                    $itemIndex,
                    'invalid_currency',
                    'price_currency must match the merchant default currency.',
                );
            }
        }

        $sourceImageUrl = self::nullableString($row['source_image_url'] ?? null);

        if ($sourceImageUrl !== null && ! filter_var($sourceImageUrl, FILTER_VALIDATE_URL)) {
            return new CuratedProductInputError($itemIndex, 'invalid_image_url', 'source_image_url must be a valid URL.');
        }

        $availability = self::nullableString($row['availability'] ?? null);

        if ($availability !== null) {
            $allowedAvailability = config('curated_catalog.availability_values', []);

            if (! is_array($allowedAvailability) || ! in_array($availability, $allowedAvailability, true)) {
                return new CuratedProductInputError($itemIndex, 'invalid_availability', 'availability is not supported.');
            }
        }

        $curationGroup = self::resolveCurationGroup($formCurationGroup, $rootCurationGroup);

        $sourcePayload = [
            'external_product_id' => $externalProductId,
            'source_url' => $normalizedUrl,
            'title' => $title,
            'price_amount' => $priceAmount,
            'price_currency' => $priceCurrency,
            'source_image_url' => $sourceImageUrl,
            'availability' => $availability,
            'captured_at' => $rootCapturedAt,
            'curation_group' => $curationGroup,
        ];

        return new CuratedMerchantProductInput(
            itemIndex: $itemIndex,
            merchantSlug: $merchantSlug,
            externalProductId: $externalProductId,
            sourceUrl: $normalizedUrl,
            title: $title,
            priceAmount: $priceAmount,
            priceCurrency: $priceCurrency,
            sourceImageUrl: $sourceImageUrl,
            availability: $availability,
            capturedAt: $rootCapturedAt,
            curationGroup: $curationGroup,
            sourcePayload: $sourcePayload,
        );
    }

    public static function resolveCurationGroup(?string $formCurationGroup, ?string $rootCurationGroup): ?string
    {
        $group = self::nullableString($formCurationGroup) ?? self::nullableString($rootCurationGroup);

        if ($group === null) {
            return null;
        }

        $allowed = config('curated_catalog.curation_groups', []);

        if (! is_array($allowed) || ! in_array($group, $allowed, true)) {
            return null;
        }

        return $group;
    }

    /**
     * @param  array<string, mixed>  $commercialConfig
     */
    private static function hostAllowed(string $host, array $commercialConfig): bool
    {
        foreach ($commercialConfig['domains'] ?? [] as $domain) {
            if (! is_string($domain)) {
                continue;
            }

            $normalized = strtolower($domain);

            if ($host === $normalized || str_ends_with($host, '.'.$normalized)) {
                return true;
            }
        }

        return false;
    }

    public static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public static function nullablePrice(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        $string = self::nullableString($value);

        if ($string === null) {
            return null;
        }

        $normalized = str_replace([',', '₹', ' '], '', $string);

        if (! is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }
}
