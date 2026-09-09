<?php

namespace App\CuratedCatalog;

use App\Enums\TaxonomyGapSeverity;

readonly class CuratedTaxonomyGap
{
    public function __construct(
        public bool $detected = false,
        public ?string $suggestedConcept = null,
        public ?string $explanation = null,
        public ?TaxonomyGapSeverity $severity = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return new self;
        }

        $detected = $raw['detected'] ?? false;

        if (! is_bool($detected)) {
            $detected = filter_var($detected, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        return new self(
            detected: $detected,
            suggestedConcept: self::nullableString($raw['suggested_concept'] ?? null),
            explanation: self::nullableString($raw['explanation'] ?? null),
            severity: self::severityFrom($raw['severity'] ?? null),
        );
    }

    /**
     * Derive severity when the model omitted it.
     *
     * A usable primary Category is an advisory specificity gap. No usable
     * primary is blocking. An explicit model severity is preserved.
     */
    public function withResolvedSeverity(?int $primaryCategoryId): self
    {
        if (! $this->detected) {
            return new self(
                detected: false,
                suggestedConcept: $this->suggestedConcept,
                explanation: $this->explanation,
                severity: null,
            );
        }

        $severity = $this->severity ?? (
            $primaryCategoryId !== null
                ? TaxonomyGapSeverity::Advisory
                : TaxonomyGapSeverity::Blocking
        );

        return new self(
            detected: true,
            suggestedConcept: $this->suggestedConcept,
            explanation: $this->explanation,
            severity: $severity,
        );
    }

    public function isAdvisory(): bool
    {
        return $this->detected && $this->severity === TaxonomyGapSeverity::Advisory;
    }

    public function isBlocking(): bool
    {
        return $this->detected && $this->severity === TaxonomyGapSeverity::Blocking;
    }

    /**
     * @return array{detected: bool, severity: ?string, suggested_concept: ?string, explanation: ?string}
     */
    public function toArray(): array
    {
        return [
            'detected' => $this->detected,
            'severity' => $this->severity?->value,
            'suggested_concept' => $this->suggestedConcept,
            'explanation' => $this->explanation,
        ];
    }

    private static function severityFrom(mixed $value): ?TaxonomyGapSeverity
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return TaxonomyGapSeverity::tryFrom($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
