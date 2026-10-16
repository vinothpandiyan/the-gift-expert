<?php

namespace App\RecipientEligibility;

/**
 * Fixed set of public discovery states used to compare strict and eligibility-aware
 * behavior. Wave-1 relationships are exercised with refinements; control states
 * (non-wave-1 relationships, unrefined pages) must never change when flags are on.
 */
final class AuditStateMatrix
{
    /**
     * @return list<array{label: string, surface: string, slug: string|null, query: array<string, string>, control: bool}>
     */
    public static function states(): array
    {
        $wave = RecipientEligibilityConfig::waveOneSlugs();
        $control = ['father', 'brother', 'friends'];
        $states = [];

        foreach (array_merge($wave, $control) as $slug) {
            $states[] = self::state('relationship', $slug, [], in_array($slug, $control, true));
        }

        foreach ($wave as $slug) {
            $states[] = self::state('relationship', $slug, ['occasion' => 'birthday']);
            $states[] = self::state('relationship', $slug, ['occasion' => 'birthday', 'interest' => 'fitness']);
            $states[] = self::state('relationship', $slug, ['interest' => 'gaming']);
            $states[] = self::state('relationship', $slug, ['category' => 'electronics']);
            $states[] = self::state('occasion', 'birthday', ['relationship' => $slug]);
            $states[] = self::state('gift_ideas', null, ['relationship' => $slug]);
        }

        $states[] = self::state('occasion', 'birthday', ['relationship' => 'husband', 'interest' => 'fitness']);
        $states[] = self::state('occasion', 'birthday', [], true);
        $states[] = self::state('occasion', 'valentines-day', ['relationship' => 'boyfriend']);
        $states[] = self::state('category', 'electronics', ['relationship' => 'husband']);
        $states[] = self::state('category', 'sports-and-outdoors', ['relationship' => 'husband']);
        $states[] = self::state('category', 'toys-and-games', ['relationship' => 'husband']);
        $states[] = self::state('interest', 'fitness', ['relationship' => 'husband']);
        $states[] = self::state('interest', 'gaming', ['relationship' => 'husband']);
        $states[] = self::state('recipient_type', 'kids', [], true);
        $states[] = self::state('recipient_type', 'kids', ['relationship' => 'husband']);
        $states[] = self::state('gift_ideas', null, [], true);

        return $states;
    }

    /**
     * @param  array<string, string>  $query
     * @return array{label: string, surface: string, slug: string|null, query: array<string, string>, control: bool}
     */
    private static function state(string $surface, ?string $slug, array $query, bool $control = false): array
    {
        $label = $surface.($slug !== null ? ':'.$slug : '');

        if ($query !== []) {
            $label .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return [
            'label' => $label,
            'surface' => $surface,
            'slug' => $slug,
            'query' => $query,
            'control' => $control,
        ];
    }

    /**
     * Finder answers replayed alongside the stored golden sessions.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function extraFinderInputs(): array
    {
        return [
            'extra:husband-birthday-fitness' => ['relationship' => 'husband', 'occasion' => 'birthday', 'interests' => ['fitness']],
            'extra:husband-birthday-none' => ['relationship' => 'husband', 'occasion' => 'birthday', 'interests' => []],
            'extra:wife-anniversary-selfcare' => ['relationship' => 'wife', 'occasion' => 'anniversary', 'interests' => ['self-care-wellness']],
            'extra:boyfriend-valentines-gaming' => ['relationship' => 'boyfriend', 'occasion' => 'valentines-day', 'interests' => ['gaming']],
            'extra:girlfriend-birthday-travel' => ['relationship' => 'girlfriend', 'occasion' => 'birthday', 'interests' => ['travel']],
        ];
    }
}
