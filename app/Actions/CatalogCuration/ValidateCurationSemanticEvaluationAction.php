<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\ProductCurationEvidence;
use App\CommercialSourcing\CommercialEnrichmentException;
use App\CommercialSourcing\CommercialTaxonomyCatalog;
use App\Enums\CurationAiConfidence;
use App\Enums\CurationFitStrength;
use App\Enums\CurationIssueCode;
use App\Enums\CurationIssueSeverity;
use App\Enums\GiftIntent;
use Illuminate\Support\Str;

class ValidateCurationSemanticEvaluationAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{evaluation: array<string, mixed>, issues: list<array<string, mixed>>}
     */
    public function execute(
        array $payload,
        ProductCurationEvidence $evidence,
        CommercialTaxonomyCatalog $catalog,
    ): array {
        $this->assertExactKeys($payload, [
            'gift_components',
            'gift_intents',
            'current_taxonomy_evaluations',
            'taxonomy_suggestions',
            'concept_key_candidate',
            'concept_label_candidate',
            'why_this_gift',
            'confidence',
            'strengths',
            'differentiation_signals',
            'differentiation_strength',
            'niche_signals',
            'niche_contribution',
        ], 'response');

        $components = $this->components($payload['gift_components']);
        $intents = $this->intents($payload['gift_intents']);
        $conceptLabelCandidate = $this->boundedString(
            $payload['concept_label_candidate'],
            1,
            (int) config('catalog_curation.limits.concept_label_candidate', 160),
            'concept_label_candidate',
        );
        $conceptKeyCandidate = $this->conceptKeyCandidate(
            $payload['concept_key_candidate'],
            $conceptLabelCandidate,
        );
        $concept = $this->concept($conceptKeyCandidate, $conceptLabelCandidate);
        $why = $this->boundedString(
            $payload['why_this_gift'],
            1,
            (int) config('catalog_curation.limits.why_this_gift', 240),
            'why_this_gift',
        );
        $confidence = $this->enumValue($payload['confidence'], CurationAiConfidence::class, 'confidence');
        $dimensions = (array) config('catalog_curation.dimensions', []);
        $this->assertExactKeys($payload['current_taxonomy_evaluations'], $dimensions, 'current_taxonomy_evaluations');
        $this->assertExactKeys($payload['taxonomy_suggestions'], $dimensions, 'taxonomy_suggestions');

        $catalogRows = $this->catalogRows($catalog);
        $issues = [];
        $current = [];
        $suggestions = [];

        foreach ($dimensions as $dimension) {
            $current[$dimension] = $this->resolveCurrentEvaluations(
                $payload['current_taxonomy_evaluations'][$dimension],
                $evidence->taxonomy[$dimension] ?? [],
                $catalogRows[$dimension],
                $dimension,
                $issues,
            );
            $suggestions[$dimension] = $this->resolveSuggestions(
                $payload['taxonomy_suggestions'][$dimension],
                $catalogRows[$dimension],
                $dimension,
                $issues,
            );
        }

        return [
            'evaluation' => [
                'gift_components' => $components,
                'gift_intents' => $intents,
                'current_taxonomy_evaluations' => $current,
                'taxonomy_suggestions' => $suggestions,
                'concept_key_candidate' => $conceptKeyCandidate,
                'concept_label_candidate' => $conceptLabelCandidate,
                'concept_key' => $concept['key'],
                'concept_label' => $concept['label'],
                'why_this_gift' => $why,
                'confidence' => $confidence,
                'strengths' => $this->stringList(
                    $payload['strengths'],
                    (int) config('catalog_curation.limits.strengths', 5),
                    'strengths',
                ),
                'differentiation_signals' => $this->stringList($payload['differentiation_signals'], 5, 'differentiation_signals'),
                'differentiation_strength' => $this->contributionStrength($payload['differentiation_strength'], 'differentiation_strength'),
                'niche_signals' => $this->stringList($payload['niche_signals'], 5, 'niche_signals'),
                'niche_contribution' => $this->contributionStrength($payload['niche_contribution'], 'niche_contribution'),
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, int|null>
     */
    private function components(mixed $raw): array
    {
        $maxima = (array) config('catalog_curation.gift_score.semantic_components', []);
        $this->assertExactKeys($raw, array_keys($maxima), 'gift_components');
        $components = [];

        foreach ($maxima as $key => $maximum) {
            $value = $raw[$key];

            if ($key === 'value_for_money' && $value === null) {
                $components[$key] = null;

                continue;
            }

            if (! is_int($value) || $value < 0 || $value > (int) $maximum) {
                $this->invalid("gift_components.{$key}");
            }

            $components[$key] = $value;
        }

        return $components;
    }

    /**
     * @return list<string>
     */
    private function intents(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > (int) config('catalog_curation.limits.gift_intents', 3)) {
            $this->invalid('gift_intents');
        }

        $values = [];

        foreach ($raw as $value) {
            $values[] = $this->enumValue($value, GiftIntent::class, 'gift_intents');
        }

        if (count($values) !== count(array_unique($values))) {
            $this->invalid('gift_intents');
        }

        return $values;
    }

    /**
     * @param  list<array{id: int, name: string, slug: string}>  $assigned
     * @param  list<array<string, mixed>>  $catalog
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function resolveCurrentEvaluations(
        mixed $raw,
        array $assigned,
        array $catalog,
        string $dimension,
        array &$issues,
    ): array {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $this->invalid("current_taxonomy_evaluations.{$dimension}");
        }

        $expected = array_values(array_unique(array_column($assigned, 'slug')));
        $resolved = [];

        foreach ($raw as $index => $item) {
            $this->assertExactKeys(
                $item,
                ['name', 'slug', 'strength', 'reason', 'misleading_on_targeted_landing_page'],
                "current_taxonomy_evaluations.{$dimension}.{$index}",
            );
            $row = $this->resolveCanonical($item, $catalog, $dimension)
                ?? $this->resolveCanonical($item, $assigned, $dimension);

            if ($row === null || ! in_array($row['slug'], $expected, true)) {
                $issues[] = [
                    'code' => CurationIssueCode::UnresolvedTaxonomyLabel->value,
                    'severity' => CurationIssueSeverity::Material->value,
                    'message' => 'A current taxonomy evaluation did not resolve to its assigned canonical taxonomy.',
                    'context' => [
                        'dimension' => $dimension,
                        'name' => is_string($item['name'] ?? null) ? $item['name'] : 'Unknown',
                        'slug' => is_string($item['slug'] ?? null) ? $item['slug'] : 'unknown',
                        'forces_human_review' => true,
                    ],
                ];

                continue;
            }

            if (in_array($row['slug'], array_column($resolved, 'slug'), true)) {
                $this->invalid("current_taxonomy_evaluations.{$dimension}.{$index}");
            }

            $resolved[] = [
                ...$row,
                'strength' => $this->enumValue($item['strength'], CurationFitStrength::class, 'strength'),
                'reason' => $this->boundedString($item['reason'], 1, 1000, 'reason'),
                'misleading_on_targeted_landing_page' => $this->boolean(
                    $item['misleading_on_targeted_landing_page'],
                    'misleading_on_targeted_landing_page',
                ),
            ];
        }

        $actual = array_values(array_unique(array_column($resolved, 'slug')));

        foreach (array_diff($expected, $actual) as $missingSlug) {
            $assignment = collect($assigned)->firstWhere('slug', $missingSlug);
            $issues[] = [
                'code' => CurationIssueCode::MissingCurrentAssignmentEvaluation->value,
                'severity' => CurationIssueSeverity::Material->value,
                'message' => 'The semantic evaluator did not assess a current taxonomy assignment.',
                'context' => [
                    'dimension' => $dimension,
                    'taxonomy' => $assignment,
                    'forces_human_review' => true,
                ],
            ];
        }

        return $resolved;
    }

    /**
     * @param  list<array<string, mixed>>  $catalog
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function resolveSuggestions(mixed $raw, array $catalog, string $dimension, array &$issues): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $this->invalid("taxonomy_suggestions.{$dimension}");
        }

        $resolved = [];

        foreach ($raw as $index => $item) {
            $this->assertExactKeys($item, ['name', 'slug', 'strength', 'reason'], "taxonomy_suggestions.{$dimension}.{$index}");
            $strength = $this->enumValue($item['strength'], CurationFitStrength::class, 'strength');
            $row = $this->resolveCanonical($item, $catalog, $dimension);

            if ($row === null) {
                $issues[] = [
                    'code' => CurationIssueCode::UnresolvedTaxonomyLabel->value,
                    'severity' => CurationIssueSeverity::Advisory->value,
                    'message' => 'AI taxonomy suggestion did not match active canonical taxonomy.',
                    'context' => [
                        'dimension' => $dimension,
                        'name' => $item['name'],
                        'slug' => $item['slug'],
                        'current_assignment' => false,
                        'forces_human_review' => false,
                    ],
                ];

                continue;
            }

            $resolved[$row['id']] = [
                ...$row,
                'strength' => $strength,
                'reason' => $this->boundedString($item['reason'], 1, 1000, 'reason'),
            ];
        }

        return array_values($resolved);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $catalog
     * @return array{id: int, name: string, slug: string}|null
     */
    private function resolveCanonical(array $item, array $catalog, string $dimension): ?array
    {
        if (! is_string($item['name']) || ! is_string($item['slug'])) {
            $this->invalid($dimension);
        }

        $name = $this->normalize($item['name']);
        $slug = $this->normalize($item['slug']);
        $aliases = (array) config("catalog_curation.aliases.{$dimension}", []);
        $slug = $this->normalize((string) ($aliases[$slug] ?? $slug));

        foreach ($catalog as $row) {
            if ($this->normalize((string) $row['slug']) === $slug || $this->normalize((string) $row['name']) === $name) {
                return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'slug' => (string) $row['slug']];
            }
        }

        return null;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function catalogRows(CommercialTaxonomyCatalog $catalog): array
    {
        return [
            'relationships' => $catalog->relationships,
            'occasions' => $catalog->occasions,
            'interests' => $catalog->interests,
            'gift_types' => $catalog->giftTypes,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw, int $maximum, string $path): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > $maximum) {
            $this->invalid($path);
        }

        return array_map(fn (mixed $value): string => $this->boundedString($value, 1, 500, $path), $raw);
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    private function enumValue(mixed $value, string $enum, string $path): string
    {
        if (! is_string($value) || $enum::tryFrom($value) === null) {
            $this->invalid($path);
        }

        return $value;
    }

    private function boundedString(mixed $value, int $minimum, int $maximum, string $path): string
    {
        if (! is_string($value) || mb_strlen(trim($value)) < $minimum || mb_strlen(trim($value)) > $maximum) {
            $this->invalid($path);
        }

        return trim($value);
    }

    private function boolean(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            $this->invalid($path);
        }

        return $value;
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertExactKeys(mixed $raw, array $keys, string $path): void
    {
        if (! is_array($raw) || array_is_list($raw)) {
            $this->invalid($path);
        }

        $actual = array_keys($raw);
        sort($actual);
        sort($keys);

        if ($actual !== $keys) {
            $this->invalid($path);
        }
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * @return array{key: string, label: string}
     */
    private function concept(string $candidate, string $label): array
    {
        $key = Str::slug($candidate);
        $aliases = (array) config('catalog_curation.aliases.concepts', []);
        $alias = $aliases[$key] ?? null;

        if (is_array($alias)) {
            $key = Str::slug((string) ($alias['key'] ?? $key));
            $label = $this->boundedString(
                $alias['label'] ?? $label,
                1,
                (int) config('catalog_curation.limits.concept_label_candidate', 160),
                'concept_alias.label',
            );
        } elseif (is_string($alias)) {
            $key = Str::slug($alias);
        }

        if ($key === '') {
            $this->invalid('concept_key_candidate');
        }

        return ['key' => $key, 'label' => $label];
    }

    private function conceptKeyCandidate(mixed $candidate, string $fallbackLabel): string
    {
        $maximum = (int) config('catalog_curation.limits.concept_key_candidate', 120);

        if (! is_string($candidate) || mb_strlen(trim($candidate)) > $maximum) {
            $this->invalid('concept_key_candidate');
        }

        return trim($candidate) !== '' ? trim($candidate) : $fallbackLabel;
    }

    private function contributionStrength(mixed $value, string $path): string
    {
        if (! is_string($value) || ! in_array($value, ['strong', 'medium', 'weak', 'none'], true)) {
            $this->invalid($path);
        }

        return $value;
    }

    private function invalid(string $path): never
    {
        throw new CommercialEnrichmentException("The curation semantic response was invalid at [{$path}].");
    }
}
