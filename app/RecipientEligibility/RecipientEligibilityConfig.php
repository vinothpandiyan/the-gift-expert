<?php

namespace App\RecipientEligibility;

/**
 * Reads config/recipient_eligibility.php. Every gate requires the master switch,
 * so turning it off restores strict behavior everywhere.
 */
final class RecipientEligibilityConfig
{
    public const MODE_OFF = 'off';

    public const MODE_INFERRED = 'inferred';

    public const CONTRADICTIONS_OFF = 'off';

    public const CONTRADICTIONS_SHADOW = 'shadow';

    public const CONTRADICTIONS_ENFORCE = 'enforce';

    public static function enabled(): bool
    {
        return config('recipient_eligibility.enabled') === true;
    }

    public static function shadowEnabled(): bool
    {
        return config('recipient_eligibility.shadow') === true;
    }

    public static function shadowSampleRate(): float
    {
        return max(0.0, min(1.0, (float) config('recipient_eligibility.shadow_sample_rate', 0.1)));
    }

    /**
     * @return array{wave: int|null, gender: string|null, infer: bool}|null
     */
    public static function profile(string $relationshipSlug): ?array
    {
        $profile = config('recipient_eligibility.relationships.'.$relationshipSlug);

        return is_array($profile) ? $profile : null;
    }

    /**
     * @return list<string>
     */
    public static function profileSlugs(): array
    {
        return array_keys((array) config('recipient_eligibility.relationships', []));
    }

    /**
     * @return list<string>
     */
    public static function waveOneSlugs(): array
    {
        return collect((array) config('recipient_eligibility.relationships', []))
            ->filter(fn (array $profile): bool => ($profile['wave'] ?? null) === 1)
            ->keys()
            ->values()
            ->all();
    }

    public static function isGuarded(string $relationshipSlug): bool
    {
        return (self::profile($relationshipSlug)['wave'] ?? null) === 1;
    }

    public static function impliedGenderSlug(string $relationshipSlug): ?string
    {
        $gender = self::profile($relationshipSlug)['gender'] ?? null;

        return is_string($gender) && $gender !== '' ? $gender : null;
    }

    public static function surfaceMode(string $surface): string
    {
        if ($surface === 'seo_landing') {
            return self::MODE_OFF;
        }

        $mode = config('recipient_eligibility.surfaces.'.$surface, self::MODE_OFF);

        return $mode === self::MODE_INFERRED ? self::MODE_INFERRED : self::MODE_OFF;
    }

    public static function surfaceActive(string $surface): bool
    {
        return self::enabled() && self::surfaceMode($surface) === self::MODE_INFERRED;
    }

    /**
     * Wave-1 relationships with their own `infer` flag on.
     *
     * @return list<string>
     */
    public static function inferringSlugs(): array
    {
        return collect(self::waveOneSlugs())
            ->filter(fn (string $slug): bool => (self::profile($slug)['infer'] ?? false) === true)
            ->filter(fn (string $slug): bool => self::reviewGateApproves($slug))
            ->values()
            ->all();
    }

    /**
     * Whether the relationship's reviewed sample cleared the suitability threshold.
     * Always true when the gate is not required (tests, local experiments).
     */
    public static function reviewGateApproves(string $slug): bool
    {
        if (config('recipient_eligibility.review_gate.required') !== true) {
            return true;
        }

        $file = (string) config('recipient_eligibility.review_gate.file');

        if ($file === '' || ! is_file($file)) {
            return false;
        }

        $gate = json_decode((string) file_get_contents($file), true);
        $entry = is_array($gate) ? ($gate['relationships'][$slug] ?? null) : null;

        return is_array($entry)
            && ($entry['passed'] ?? false) === true
            && (float) ($entry['suitability'] ?? 0) >= (float) config('recipient_eligibility.audit.min_suitability', 0.95);
    }

    public static function contradictionsMode(): string
    {
        if (! self::enabled()) {
            return self::CONTRADICTIONS_OFF;
        }

        $mode = (string) config('recipient_eligibility.explicit_contradictions', self::CONTRADICTIONS_OFF);

        return in_array($mode, [self::CONTRADICTIONS_SHADOW, self::CONTRADICTIONS_ENFORCE], true)
            ? $mode
            : self::CONTRADICTIONS_OFF;
    }

    public static function enforcesExplicitContradictions(): bool
    {
        return self::contradictionsMode() === self::CONTRADICTIONS_ENFORCE;
    }

    public static function finderAudience(): bool
    {
        return self::enabled() && config('recipient_eligibility.finder_audience') === true;
    }

    public static function poolOrdering(): bool
    {
        return self::enabled() && config('recipient_eligibility.pool_ordering') === true;
    }

    public static function inferredWeight(): int
    {
        return (int) config('recipient_eligibility.inferred_relationship_weight', 6);
    }

    /**
     * Live mode for a surface, or null when the surface behaves strictly.
     */
    public static function context(string $surface): ?RecipientEligibilityContext
    {
        if (! self::surfaceActive($surface)) {
            return null;
        }

        $context = new RecipientEligibilityContext(
            surface: $surface,
            inferRelationshipSlugs: self::inferringSlugs(),
            enforceExplicitContradictions: self::enforcesExplicitContradictions(),
        );

        return $context->infersAny() || $context->enforceExplicitContradictions ? $context : null;
    }

    /**
     * Finder context: stage 2/3 inference when the finder surface is on.
     * Contradiction filtering for all stages is handled separately (finderAudience).
     */
    public static function finderContext(): ?RecipientEligibilityContext
    {
        return self::context('finder');
    }

    /**
     * What the surface would do if wave 1 were fully enabled. Read-only; used by
     * the audit command and sampled runtime shadow logging.
     */
    public static function shadowContext(string $surface): RecipientEligibilityContext
    {
        return new RecipientEligibilityContext(
            surface: $surface,
            inferRelationshipSlugs: self::waveOneSlugs(),
            enforceExplicitContradictions: true,
        );
    }
}
