<?php

namespace Tests\Support;

/**
 * Explicit recipient-eligibility configuration for tests.
 *
 * phpunit.xml pins the RECIPIENT_ELIGIBILITY_* environment to the shipped
 * defaults, so a developer's .env never reaches the suite. Tests that need a
 * particular mode say so with one of these helpers instead of relying on the
 * ambient environment.
 */
trait ConfiguresRecipientEligibility
{
    /**
     * Strict mode: the shipped defaults. No inference, no contradiction
     * handling, no shadow logging, no pool re-ordering.
     */
    protected function useStrictRecipientEligibility(): void
    {
        $settings = [
            'enabled' => false,
            'shadow' => false,
            'explicit_contradictions' => 'off',
            'finder_audience' => false,
            'pool_ordering' => false,
            'review_gate.required' => true,
            'relationships.husband.infer' => false,
            'relationships.wife.infer' => false,
            'relationships.boyfriend.infer' => false,
            'relationships.girlfriend.infer' => false,
        ];

        foreach (array_keys((array) config('recipient_eligibility.surfaces')) as $surface) {
            $settings['surfaces.'.$surface] = 'off';
        }

        foreach ($settings as $key => $value) {
            config(['recipient_eligibility.'.$key => $value]);
        }
    }

    /**
     * Master switch on, listing surfaces on, wave-1 inference on. Pass overrides
     * (dot keys under recipient_eligibility.*) to vary a case.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function enableRecipientEligibility(array $overrides = []): void
    {
        $settings = [
            'enabled' => true,
            'review_gate.required' => false,
            'surfaces.relationship' => 'inferred',
            'surfaces.occasion' => 'inferred',
            'surfaces.category' => 'inferred',
            'surfaces.interest' => 'inferred',
            'surfaces.gift_ideas' => 'inferred',
            'relationships.husband.infer' => true,
            'relationships.wife.infer' => true,
            'relationships.boyfriend.infer' => true,
            'relationships.girlfriend.infer' => true,
        ];

        foreach ($overrides + $settings as $key => $value) {
            config(['recipient_eligibility.'.$key => $value]);
        }
    }
}
