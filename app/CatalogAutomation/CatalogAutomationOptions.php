<?php

namespace App\CatalogAutomation;

use App\Enums\CatalogAutomationStage;
use InvalidArgumentException;

readonly class CatalogAutomationOptions
{
    /**
     * @param  list<string>  $sourceCategories
     */
    public function __construct(
        public string $brief,
        public string $market,
        public int $maxCandidates,
        public int $freshnessDays,
        public ?int $candidateLimit,
        public bool $dryRun,
        public CatalogAutomationStage $stopAfter,
        public bool $noEnrichExisting,
        public ?int $createdByUserId = null,
        public array $sourceCategories = [],
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function from(array $attributes): self
    {
        $brief = $attributes['brief'] ?? null;

        if (! is_string($brief) || trim($brief) === '') {
            throw new InvalidArgumentException('A research brief is required.');
        }

        $market = $attributes['market'] ?? config('catalog_automation.default_market', 'IN');

        if (! is_string($market) || preg_match('/^[A-Z]{2}$/', strtoupper(trim($market))) !== 1) {
            throw new InvalidArgumentException('Market must be a 2-letter ISO country code.');
        }

        $maxCandidates = self::positiveInt(
            $attributes['max'] ?? $attributes['max_candidates'] ?? config('catalog_automation.max_candidates_per_run', 10),
            'Maximum candidates',
        );
        $maxCap = max(1, (int) config('catalog_automation.max_candidates_per_run', 20));
        $maxCandidates = min($maxCandidates, $maxCap);

        $freshnessDays = self::positiveInt(
            $attributes['freshness_days'] ?? config('catalog_candidate_discovery.default_freshness_days', 30),
            'Freshness days',
        );

        $candidateLimit = $attributes['candidate_limit'] ?? null;

        if ($candidateLimit !== null && $candidateLimit !== '') {
            $candidateLimit = self::positiveInt($candidateLimit, 'Candidate limit');
        } else {
            $candidateLimit = null;
        }

        $stopAfter = self::stopAfter($attributes['stop_after'] ?? 'readiness');

        return new self(
            brief: trim($brief),
            market: strtoupper(trim($market)),
            maxCandidates: $maxCandidates,
            freshnessDays: $freshnessDays,
            candidateLimit: $candidateLimit,
            dryRun: (bool) ($attributes['dry_run'] ?? false),
            stopAfter: $stopAfter,
            noEnrichExisting: (bool) ($attributes['no_enrich_existing'] ?? false),
            createdByUserId: isset($attributes['created_by_user_id']) ? (int) $attributes['created_by_user_id'] : null,
            sourceCategories: is_array($attributes['source_categories'] ?? null) ? $attributes['source_categories'] : [],
        );
    }

    public function includeDiscovered(): bool
    {
        return config('catalog_automation.candidate_gate', 'discovered_and_approved') === 'discovered_and_approved';
    }

    public function continueExistingCandidates(): bool
    {
        return (bool) config('catalog_automation.continue_existing_candidates', true);
    }

    public function reSourceExisting(): bool
    {
        return (bool) config('catalog_automation.re_source_existing', false);
    }

    public function maxProductsPromotedPerRun(): int
    {
        return max(1, (int) config('catalog_automation.max_products_promoted_per_run', 20));
    }

    private static function stopAfter(mixed $value): CatalogAutomationStage
    {
        if (! is_string($value) || trim($value) === '') {
            return CatalogAutomationStage::Readiness;
        }

        $normalized = strtolower(trim($value));

        return CatalogAutomationStage::tryFrom($normalized)
            ?? throw new InvalidArgumentException("Unknown stop-after stage [{$value}].");
    }

    private static function positiveInt(mixed $value, string $label): int
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("{$label} must be a positive integer.");
        }

        return $value;
    }
}
