<?php

namespace App\CatalogCandidate\Ingestion;

use App\Enums\CatalogCandidatePriority;
use App\Enums\CatalogCandidateSourceType;
use Carbon\Carbon;
use JsonException;
use Throwable;

final class CatalogCandidateIngestionFields
{
    /**
     * @var list<string>
     */
    public const CANDIDATE_KEYS = [
        'title',
        'summary',
        'notes',
        'priority',
        'source_type',
        'source_name',
        'source_url',
        'external_reference',
        'estimated_price_amount',
        'estimated_price_currency',
        'discovered_at',
        'allow_similar_title',
        'evidence',
    ];

    /**
     * @var list<string>
     */
    public const CSV_KEYS = [
        'title',
        'summary',
        'notes',
        'priority',
        'source_type',
        'source_name',
        'source_url',
        'external_reference',
        'estimated_price_amount',
        'estimated_price_currency',
        'discovered_at',
        'allow_similar_title',
    ];

    /**
     * @var list<string>
     */
    public const EVIDENCE_KEYS = [
        'source_type',
        'source_name',
        'source_url',
        'summary',
        'observed_at',
        'metadata',
    ];

    public static function assertUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new CatalogCandidateIngestionParserException('Ingestion files must be valid UTF-8.');
        }

        $maxBytes = (int) config('catalog_candidate_ingestion.max_file_bytes');

        if (strlen($contents) > $maxBytes) {
            throw new CatalogCandidateIngestionParserException('The ingestion file exceeds the maximum allowed size.');
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $allowed
     */
    public static function unknownKeys(array $row, array $allowed): array
    {
        return array_values(array_diff(array_keys($row), $allowed));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function candidateFromRow(int $index, array $row, bool $allowEvidence): IngestedCatalogCandidate|IngestionRowError
    {
        $payload = self::compactPayload($row);

        $unknown = self::unknownKeys($row, $allowEvidence ? self::CANDIDATE_KEYS : self::CSV_KEYS);

        if ($unknown !== []) {
            return new IngestionRowError(
                $index,
                'Unknown fields are not allowed: '.implode(', ', $unknown).'.',
                $payload,
                title: self::nullableString($row['title'] ?? null),
            );
        }

        $title = self::nullableString($row['title'] ?? null);

        if ($title === null) {
            return new IngestionRowError($index, 'A candidate title is required.', $payload);
        }

        $sourceType = self::sourceType($row['source_type'] ?? null);

        if (! $sourceType instanceof CatalogCandidateSourceType) {
            return new IngestionRowError($index, 'A valid candidate source type is required.', $payload, title: $title);
        }

        $priority = self::priority($row['priority'] ?? null);

        if ($priority instanceof IngestionRowError) {
            return new IngestionRowError($index, $priority->message, $payload, title: $title);
        }

        $allowSimilar = self::boolean($row['allow_similar_title'] ?? null, optional: true);

        if ($allowSimilar instanceof IngestionRowError) {
            return new IngestionRowError($index, $allowSimilar->message, $payload, title: $title);
        }

        $amount = self::price($row['estimated_price_amount'] ?? null);

        if ($amount instanceof IngestionRowError) {
            return new IngestionRowError($index, $amount->message, $payload, title: $title);
        }

        $currency = self::currency($row['estimated_price_currency'] ?? null);

        if ($currency instanceof IngestionRowError) {
            return new IngestionRowError($index, $currency->message, $payload, title: $title);
        }

        $discoveredAt = self::timestamp($row['discovered_at'] ?? null, 'discovered_at');

        if ($discoveredAt instanceof IngestionRowError) {
            return new IngestionRowError($index, $discoveredAt->message, $payload, title: $title);
        }

        $evidence = [];

        if ($allowEvidence && array_key_exists('evidence', $row)) {
            $parsedEvidence = self::evidenceList($index, $row['evidence'], $payload, $title);

            if ($parsedEvidence instanceof IngestionRowError) {
                return $parsedEvidence;
            }

            $evidence = $parsedEvidence;
        }

        return new IngestedCatalogCandidate(
            index: $index,
            title: $title,
            sourceType: $sourceType,
            summary: self::nullableString($row['summary'] ?? null),
            notes: self::nullableString($row['notes'] ?? null),
            priority: $priority,
            sourceName: self::nullableString($row['source_name'] ?? null),
            sourceUrl: self::nullableString($row['source_url'] ?? null),
            externalReference: self::nullableString($row['external_reference'] ?? null),
            estimatedPriceAmount: $amount,
            estimatedPriceCurrency: $currency,
            discoveredAt: $discoveredAt,
            allowSimilarTitle: $allowSimilar ?? false,
            evidence: $evidence,
            sourcePayload: $payload,
        );
    }

    public static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function compactPayload(array $row): array
    {
        $payload = [];

        foreach ($row as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    private static function sourceType(mixed $value): ?CatalogCandidateSourceType
    {
        if ($value instanceof CatalogCandidateSourceType) {
            return $value;
        }

        $string = self::nullableString(is_string($value) ? $value : null);

        if ($string === null) {
            return null;
        }

        return CatalogCandidateSourceType::tryFrom($string);
    }

    private static function priority(mixed $value): CatalogCandidatePriority|IngestionRowError|null
    {
        if ($value instanceof CatalogCandidatePriority) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        $string = self::nullableString(is_string($value) ? $value : null);

        if ($string === null) {
            return new IngestionRowError(0, 'A valid candidate priority is required.');
        }

        $resolved = CatalogCandidatePriority::tryFrom($string);

        if ($resolved === null) {
            return new IngestionRowError(0, 'A valid candidate priority is required.');
        }

        return $resolved;
    }

    private static function boolean(mixed $value, bool $optional): bool|IngestionRowError|null
    {
        if ($value === null || $value === '') {
            return $optional ? null : new IngestionRowError(0, 'A boolean value is required.');
        }

        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return new IngestionRowError(0, 'allow_similar_title must be true or false.');
        }

        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '0', 'false', 'no' => false,
            '1', 'true', 'yes' => true,
            default => new IngestionRowError(0, 'allow_similar_title must be true, false, 1, 0, yes, or no.'),
        };
    }

    private static function price(mixed $value): string|IngestionRowError|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        $string = self::nullableString(is_string($value) ? $value : null);

        if ($string === null || ! is_numeric($string)) {
            return new IngestionRowError(0, 'estimated_price_amount must be numeric.');
        }

        return number_format((float) $string, 2, '.', '');
    }

    private static function currency(mixed $value): string|IngestionRowError|null
    {
        $string = self::nullableString(is_string($value) ? $value : null);

        if ($string === null) {
            if ($value !== null && $value !== '') {
                return new IngestionRowError(0, 'estimated_price_currency must be a 3-letter code.');
            }

            return null;
        }

        if (strlen($string) !== 3) {
            return new IngestionRowError(0, 'estimated_price_currency must be a 3-letter code.');
        }

        return strtoupper($string);
    }

    private static function timestamp(mixed $value, string $field): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        $string = self::nullableString(is_string($value) ? $value : null);

        if ($string === null) {
            return new IngestionRowError(0, "{$field} must be a valid timestamp.");
        }

        try {
            return Carbon::parse($string);
        } catch (Throwable) {
            return new IngestionRowError(0, "{$field} must be a valid timestamp.");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<IngestedCatalogCandidateEvidence>|IngestionRowError
     */
    private static function evidenceList(int $index, mixed $evidence, array $payload, string $title): array|IngestionRowError
    {
        if (! is_array($evidence) || ! array_is_list($evidence)) {
            return new IngestionRowError($index, 'evidence must be an array of objects.', $payload, title: $title);
        }

        $maxEvidence = (int) config('catalog_candidate_ingestion.max_evidence_per_candidate');

        if (count($evidence) > $maxEvidence) {
            return new IngestionRowError($index, 'A candidate may not include more than '.$maxEvidence.' evidence items.', $payload, title: $title);
        }

        $parsed = [];

        foreach ($evidence as $offset => $item) {
            if (! is_array($item) || array_is_list($item)) {
                return new IngestionRowError($index, 'Each evidence item must be an object.', $payload, title: $title);
            }

            $unknown = self::unknownKeys($item, self::EVIDENCE_KEYS);

            if ($unknown !== []) {
                return new IngestionRowError(
                    $index,
                    'Unknown evidence fields are not allowed: '.implode(', ', $unknown).'.',
                    $payload,
                    title: $title,
                );
            }

            $sourceType = self::sourceType($item['source_type'] ?? null);

            if (! $sourceType instanceof CatalogCandidateSourceType) {
                return new IngestionRowError($index, 'A valid evidence source type is required.', $payload, title: $title);
            }

            $metadata = self::metadata($item['metadata'] ?? null);

            if ($metadata instanceof IngestionRowError) {
                return new IngestionRowError($index, $metadata->message, $payload, title: $title);
            }

            $observedAt = self::timestamp($item['observed_at'] ?? null, 'observed_at');

            if ($observedAt instanceof IngestionRowError) {
                return new IngestionRowError($index, $observedAt->message, $payload, title: $title);
            }

            $parsed[] = new IngestedCatalogCandidateEvidence(
                sourceType: $sourceType,
                sourceName: self::nullableString($item['source_name'] ?? null),
                sourceUrl: self::nullableString($item['source_url'] ?? null),
                summary: self::nullableString($item['summary'] ?? null),
                observedAt: $observedAt,
                metadata: $metadata,
            );

            unset($offset);
        }

        return $parsed;
    }

    /**
     * @return array<string, scalar|null>|IngestionRowError|null
     */
    private static function metadata(mixed $value): array|IngestionRowError|null
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || array_is_list($value)) {
            return new IngestionRowError(0, 'Evidence metadata must be a JSON object.');
        }

        foreach ($value as $metadataValue) {
            if (is_array($metadataValue) || (is_object($metadataValue) && ! $metadataValue instanceof \Stringable)) {
                return new IngestionRowError(0, 'Evidence metadata values must be scalars.');
            }
        }

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new IngestionRowError(0, 'Evidence metadata is invalid.');
        }

        $maxBytes = (int) config('catalog_candidate_ingestion.max_metadata_bytes');

        if (strlen($encoded) > $maxBytes) {
            return new IngestionRowError(0, 'Evidence metadata exceeds the maximum allowed size.');
        }

        return $value;
    }
}
