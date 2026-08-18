<?php

namespace App\CatalogCandidate\Discovery;

use InvalidArgumentException;

readonly class CatalogCandidateResearchBrief
{
    /**
     * @param  list<string>  $sourceCategories
     */
    public function __construct(
        public string $brief,
        public string $market,
        public int $maxCandidates,
        public int $freshnessDays,
        public array $sourceCategories,
    ) {}

    /**
     * @param  list<mixed>|mixed  $sourceCategories
     */
    public static function from(
        mixed $brief,
        mixed $market = null,
        mixed $maxCandidates = null,
        mixed $freshnessDays = null,
        mixed $sourceCategories = [],
    ): self {
        $normalizedBrief = self::requiredBrief($brief);

        return new self(
            brief: $normalizedBrief,
            market: self::market($market),
            maxCandidates: self::maxCandidates($maxCandidates),
            freshnessDays: self::freshnessDays($freshnessDays),
            sourceCategories: self::sourceCategories($sourceCategories),
        );
    }

    private static function requiredBrief(mixed $brief): string
    {
        if (! is_string($brief)) {
            throw new InvalidArgumentException('A research brief is required.');
        }

        $brief = trim($brief);

        if ($brief === '') {
            throw new InvalidArgumentException('A research brief is required.');
        }

        return $brief;
    }

    private static function market(mixed $market): string
    {
        if ($market === null || $market === '') {
            return strtoupper((string) config('catalog_candidate_discovery.default_market', 'IN'));
        }

        if (! is_string($market)) {
            throw new InvalidArgumentException('Market must be a 2-letter ISO country code.');
        }

        $normalized = strtoupper(trim($market));

        if (preg_match('/^[A-Z]{2}$/', $normalized) !== 1) {
            throw new InvalidArgumentException('Market must be a 2-letter ISO country code.');
        }

        return $normalized;
    }

    private static function maxCandidates(mixed $maxCandidates): int
    {
        $cap = max(1, (int) config('catalog_candidate_discovery.max_candidates', 20));

        if ($maxCandidates === null || $maxCandidates === '') {
            $maxCandidates = 10;
        }

        if (is_string($maxCandidates) && is_numeric($maxCandidates)) {
            $maxCandidates = (int) $maxCandidates;
        }

        if (! is_int($maxCandidates) || $maxCandidates < 1) {
            throw new InvalidArgumentException('Maximum candidates must be a positive integer.');
        }

        return min($maxCandidates, $cap);
    }

    private static function freshnessDays(mixed $freshnessDays): int
    {
        $max = max(1, (int) config('catalog_candidate_discovery.max_freshness_days', 365));

        if ($freshnessDays === null || $freshnessDays === '') {
            $freshnessDays = (int) config('catalog_candidate_discovery.default_freshness_days', 30);
        }

        if (is_string($freshnessDays) && is_numeric($freshnessDays)) {
            $freshnessDays = (int) $freshnessDays;
        }

        if (! is_int($freshnessDays) || $freshnessDays < 1) {
            throw new InvalidArgumentException('Freshness days must be a positive integer.');
        }

        return min($freshnessDays, $max);
    }

    /**
     * @return list<string>
     */
    private static function sourceCategories(mixed $sourceCategories): array
    {
        if ($sourceCategories === null || $sourceCategories === '') {
            return [];
        }

        if (! is_array($sourceCategories)) {
            throw new InvalidArgumentException('Source categories must be a list of strings.');
        }

        $normalized = [];
        $seen = [];

        foreach ($sourceCategories as $category) {
            if (! is_string($category)) {
                throw new InvalidArgumentException('Source categories must be a list of strings.');
            }

            $category = strtolower(trim($category));

            if ($category === '' || isset($seen[$category])) {
                continue;
            }

            $seen[$category] = true;
            $normalized[] = $category;
        }

        return $normalized;
    }
}
