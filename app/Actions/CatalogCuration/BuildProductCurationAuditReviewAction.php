<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\ProductCurationAuditReview;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class BuildProductCurationAuditReviewAction
{
    public function execute(Product $product): ?ProductCurationAuditReview
    {
        $product->loadMissing('latestCompletedCurationAudit');
        $audit = $product->latestCompletedCurationAudit;

        if (! $audit instanceof ProductCurationAudit || $audit->completed_at === null) {
            return null;
        }

        return new ProductCurationAuditReview(
            auditId: $audit->id,
            completedAt: $audit->completed_at->toDateTimeString(),
            giftScore: $audit->gift_score,
            catalogValueScore: $audit->catalog_value_score,
            confidence: $this->label($audit->ai_confidence?->value),
            recommendation: $this->label($audit->recommendation?->value),
            requiresHumanReview: $audit->requires_human_review,
            conceptKey: $audit->concept_key,
            conceptLabel: $audit->concept_label,
            catalogRole: $this->label($audit->catalog_role?->value),
            intents: $this->labels($audit->gift_intents),
            whyThisGift: $audit->why_this_gift,
            strengths: $this->stringList($audit->strengths),
            issues: $this->issues($audit->issues),
            giftFactors: $this->giftFactors($audit->gift_score_components),
            catalogFactors: $this->catalogFactors($audit),
            peers: $this->peers($audit),
            fitRows: $this->fitRows($audit),
        );
    }

    /**
     * @return list<array{code: string, label: string, severity: string, message: string, context: list<array{label: string, value: string}>}>
     */
    private function issues(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn (mixed $issue): bool => is_array($issue) && filled($issue['code'] ?? null))
            ->map(function (array $issue): array {
                $context = is_array($issue['context'] ?? null) ? $issue['context'] : [];

                return [
                    'code' => (string) $issue['code'],
                    'label' => $this->label((string) $issue['code']),
                    'severity' => (string) ($issue['severity'] ?? 'warning'),
                    'message' => (string) ($issue['message'] ?? ''),
                    'context' => collect($context)
                        ->map(fn (mixed $value, mixed $key): array => [
                            'label' => $this->label((string) $key),
                            'value' => $this->displayValue($value),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, score: string, detail: ?string}>
     */
    private function giftFactors(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn (mixed $component): bool => is_array($component))
            ->map(fn (array $component, string $key): array => [
                'label' => $this->label($key),
                'score' => ($component['score'] ?? null) === null
                    ? 'Unknown'
                    : ((string) $component['score']).' / '.((string) ($component['max'] ?? '—')),
                'detail' => filled($component['evidence_status'] ?? null)
                    ? 'Evidence: '.$this->label((string) $component['evidence_status'])
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, score: string, detail: ?string}>
     */
    private function catalogFactors(ProductCurationAudit $audit): array
    {
        $factors = [
            'saturation_novelty' => $audit->saturation_novelty_factor,
            'differentiation' => $audit->differentiation_factor,
            'budget_gap' => $audit->budget_gap_factor,
            'taxonomy_gap' => $audit->taxonomy_gap_factor,
            'intents' => $audit->intents_factor,
            'niche' => $audit->niche_factor,
        ];
        $maximums = (array) config('catalog_curation.catalog_value.factors', []);

        return collect($factors)
            ->map(fn (?int $score, string $key): array => [
                'label' => $this->label($key),
                'score' => $score === null ? 'Unknown' : "{$score} / ".($maximums[$key] ?? '—'),
                'detail' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, count: int, ids: list<int>}>
     */
    private function peers(ProductCurationAudit $audit): array
    {
        $counts = is_array($audit->peer_counts) ? $audit->peer_counts : [];
        $ids = is_array($audit->peer_product_ids) ? $audit->peer_product_ids : [];

        return collect([
            'concept' => 'Concept',
            'budget_band' => 'Budget band',
            'taxonomy_signature' => 'Taxonomy signature',
            'gift_intents' => 'Gift intents',
        ])->map(fn (string $label, string $key): array => [
            'label' => $label,
            'count' => (int) ($counts[$key] ?? count((array) ($ids[$key] ?? []))),
            'ids' => array_values(array_map('intval', (array) ($ids[$key] ?? []))),
        ])->values()->all();
    }

    /**
     * @return list<array{dimension: string, current: list<string>, audit: list<string>, differences: list<array{label: string, severity: string, forces_human_review: bool}>}>
     */
    private function fitRows(ProductCurationAudit $audit): array
    {
        $semantic = is_array($audit->semantic_evaluation) ? $audit->semantic_evaluation : [];
        $current = is_array($semantic['current_taxonomy_evaluations'] ?? null)
            ? $semantic['current_taxonomy_evaluations']
            : [];
        $suggestions = is_array($semantic['taxonomy_suggestions'] ?? null)
            ? $semantic['taxonomy_suggestions']
            : [];
        $differences = is_array($audit->taxonomy_differences) ? $audit->taxonomy_differences : [];

        return collect(['relationships', 'occasions', 'interests', 'gift_types'])
            ->map(function (string $dimension) use ($current, $suggestions, $differences): array {
                $differenceLabels = collect($differences)
                    ->filter(fn (mixed $difference): bool => is_array($difference) && ($difference['dimension'] ?? null) === $dimension)
                    ->map(function (array $difference): array {
                        $name = Arr::get($difference, 'taxonomy.name', 'Unknown');
                        $action = $this->label((string) ($difference['action'] ?? 'review'));

                        return [
                            'label' => "{$action}: {$name}".(filled($difference['reason'] ?? null) ? ' — '.$difference['reason'] : ''),
                            'severity' => (string) ($difference['severity'] ?? 'material'),
                            'forces_human_review' => ($difference['forces_human_review'] ?? true) === true,
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'dimension' => $this->label($dimension),
                    'current' => $this->fitLabels($current[$dimension] ?? []),
                    'audit' => $this->fitLabels($suggestions[$dimension] ?? []),
                    'differences' => $differenceLabels,
                ];
            })
            ->push($this->applicabilityRow($differences))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $differences
     * @return array{dimension: string, current: list<string>, audit: list<string>, differences: list<array{label: string, severity: string, forces_human_review: bool}>}|null
     */
    private function applicabilityRow(array $differences): ?array
    {
        $hardConflicts = collect($differences)
            ->filter(fn (mixed $difference): bool => is_array($difference) && ($difference['cause'] ?? null) === 'hard_applicability_conflict')
            ->map(fn (array $difference): array => [
                'label' => (string) ($difference['reason'] ?? 'Authoritative applicability conflict.'),
                'severity' => 'blocking',
                'forces_human_review' => true,
            ])
            ->values()
            ->all();

        if ($hardConflicts === []) {
            return null;
        }

        return [
            'dimension' => 'Applicability',
            'current' => [],
            'audit' => [],
            'differences' => $hardConflicts,
        ];
    }

    /**
     * @return list<string>
     */
    private function fitLabels(mixed $fits): array
    {
        if (! is_array($fits)) {
            return [];
        }

        return collect($fits)
            ->filter(fn (mixed $fit): bool => is_array($fit) && filled($fit['name'] ?? null))
            ->map(function (array $fit): string {
                $label = (string) $fit['name'];

                if (filled($fit['strength'] ?? null)) {
                    $label .= ' · '.$this->label((string) $fit['strength']);
                }

                if (filled($fit['reason'] ?? null)) {
                    $label .= ' — '.(string) $fit['reason'];
                }

                return $label;
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function labels(mixed $values): array
    {
        return array_map(fn (string $value): string => $this->label($value), $this->stringList($values));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    private function displayValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return implode(', ', Arr::flatten($value));
        }

        return (string) $value;
    }

    private function label(?string $value): string
    {
        return filled($value) ? Str::of($value)->replace('_', ' ')->headline()->toString() : '—';
    }
}
