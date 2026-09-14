<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\ProductCurationAuditReview;
use App\Enums\CurationAiConfidence;
use App\Enums\CurationRecommendation;
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

        $issues = $this->issues($audit->issues);

        return new ProductCurationAuditReview(
            auditId: $audit->id,
            completedAt: $audit->completed_at->toDateTimeString(),
            giftScore: $audit->gift_score,
            catalogValueScore: $audit->catalog_value_score,
            giftScoreBand: $this->band($audit->gift_score),
            catalogValueBand: $this->band($audit->catalog_value_score),
            confidence: $this->label($audit->ai_confidence?->value),
            confidenceColor: match ($audit->ai_confidence) {
                CurationAiConfidence::High => 'success',
                CurationAiConfidence::Medium => 'warning',
                CurationAiConfidence::Low => 'danger',
                default => 'gray',
            },
            recommendation: $this->label($audit->recommendation?->value),
            recommendationColor: match ($audit->recommendation) {
                CurationRecommendation::Feature, CurationRecommendation::Keep, CurationRecommendation::KeepNiche => 'success',
                CurationRecommendation::Review => 'warning',
                CurationRecommendation::ReplaceCandidate, CurationRecommendation::RemoveCandidate => 'danger',
                default => 'gray',
            },
            requiresHumanReview: $audit->requires_human_review,
            conceptKey: $audit->concept_key,
            conceptLabel: $audit->concept_label,
            catalogRole: $this->label($audit->catalog_role?->value),
            intents: $this->labels($audit->gift_intents),
            whyThisGift: $audit->why_this_gift,
            strengths: $this->stringList($audit->strengths),
            issues: $issues,
            concerns: $this->concerns($issues),
            giftFactors: $this->giftFactors($audit->gift_score_components),
            catalogFactors: $this->catalogFactors($audit),
            peers: $this->peers($audit),
            fitRows: $this->fitRows($audit),
            catalogContext: $this->catalogContext($audit),
            metadata: $this->metadata($audit),
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
     * @param  list<array{code: string, label: string, severity: string, message: string, context: list<array{label: string, value: string}>}>  $issues
     * @return list<array{code: string, label: string, message: string, severity: string}>
     */
    private function concerns(array $issues): array
    {
        return array_map(fn (array $issue): array => [
            'code' => $issue['code'],
            'label' => $issue['label'],
            'message' => filled($issue['message']) ? $issue['message'] : $issue['label'],
            'severity' => $issue['severity'],
        ], $issues);
    }

    /**
     * @return list<array{label: string, score: string, points: int|string|null, max: int|string|null, detail: ?string}>
     */
    private function giftFactors(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn (mixed $component): bool => is_array($component))
            ->map(fn (array $component, string $key): array => $this->factorRow(
                $this->factorLabel($key),
                $component['score'] ?? null,
                $component['max'] ?? null,
                filled($component['evidence_status'] ?? null)
                    ? 'Evidence: '.$this->label((string) $component['evidence_status'])
                    : null,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, score: string, points: int|string|null, max: int|string|null, detail: ?string}>
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
            ->map(fn (?int $score, string $key): array => $this->factorRow(
                $this->factorLabel($key),
                $score,
                $maximums[$key] ?? null,
                null,
            ))
            ->values()
            ->all();
    }

    /**
     * @return array{label: string, score: string, points: int|string|null, max: int|string|null, detail: ?string}
     */
    private function factorRow(string $label, mixed $score, mixed $max, ?string $detail): array
    {
        $points = is_numeric($score) ? (int) $score : null;
        $maximum = is_numeric($max) ? (int) $max : null;

        return [
            'label' => $label,
            'score' => $points === null ? 'Unknown' : ($maximum === null ? (string) $points : $points.' / '.$maximum),
            'points' => $points,
            'max' => $maximum,
            'detail' => $detail,
        ];
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
     * @return list<array{dimension: string, current: list<string>, audit: list<string>, currentItems: list<array{name: string, strength: ?string, strength_label: ?string, strength_color: string, reason: ?string}>, auditItems: list<array{name: string, strength: ?string, strength_label: ?string, strength_color: string, reason: ?string}>, differences: list<array{label: string, action: string, action_label: string, name: string, severity: string, forces_human_review: bool}>}>
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
                $currentItems = $this->fitItems($current[$dimension] ?? []);
                $auditItems = $this->fitItems($suggestions[$dimension] ?? []);
                $differenceRows = collect($differences)
                    ->filter(fn (mixed $difference): bool => is_array($difference) && ($difference['dimension'] ?? null) === $dimension)
                    ->map(function (array $difference): array {
                        $name = (string) Arr::get($difference, 'taxonomy.name', 'Unknown');
                        $action = (string) ($difference['action'] ?? 'review');
                        $actionLabel = $this->differenceActionLabel($action);

                        return [
                            'label' => "{$actionLabel}: {$name}".(filled($difference['reason'] ?? null) ? ' — '.$difference['reason'] : ''),
                            'action' => $action,
                            'action_label' => $actionLabel,
                            'name' => $name,
                            'severity' => (string) ($difference['severity'] ?? 'material'),
                            'forces_human_review' => ($difference['forces_human_review'] ?? true) === true,
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'dimension' => $this->label($dimension),
                    'current' => array_column($currentItems, 'name'),
                    'audit' => array_column($auditItems, 'name'),
                    'currentItems' => $currentItems,
                    'auditItems' => $auditItems,
                    'differences' => $differenceRows,
                ];
            })
            ->push($this->applicabilityRow($differences))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $differences
     * @return array{dimension: string, current: list<string>, audit: list<string>, currentItems: list<array{name: string, strength: ?string, strength_label: ?string, strength_color: string, reason: ?string}>, auditItems: list<array{name: string, strength: ?string, strength_label: ?string, strength_color: string, reason: ?string}>, differences: list<array{label: string, action: string, action_label: string, name: string, severity: string, forces_human_review: bool}>}|null
     */
    private function applicabilityRow(array $differences): ?array
    {
        $hardConflicts = collect($differences)
            ->filter(fn (mixed $difference): bool => is_array($difference) && ($difference['cause'] ?? null) === 'hard_applicability_conflict')
            ->map(fn (array $difference): array => [
                'label' => (string) ($difference['reason'] ?? 'Authoritative applicability conflict.'),
                'action' => 'conflict',
                'action_label' => 'Conflict',
                'name' => (string) Arr::get($difference, 'taxonomy.name', ''),
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
            'currentItems' => [],
            'auditItems' => [],
            'differences' => $hardConflicts,
        ];
    }

    /**
     * @return list<array{name: string, strength: ?string, strength_label: ?string, strength_color: string, reason: ?string}>
     */
    private function fitItems(mixed $fits): array
    {
        if (! is_array($fits)) {
            return [];
        }

        return collect($fits)
            ->filter(fn (mixed $fit): bool => is_array($fit) && filled($fit['name'] ?? null))
            ->map(function (array $fit): array {
                $strength = filled($fit['strength'] ?? null) ? (string) $fit['strength'] : null;

                return [
                    'name' => (string) $fit['name'],
                    'strength' => $strength,
                    'strength_label' => $strength !== null ? $this->strengthLabel($strength) : null,
                    'strength_color' => $this->strengthColor($strength),
                    'reason' => filled($fit['reason'] ?? null) ? (string) $fit['reason'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function catalogContext(ProductCurationAudit $audit): array
    {
        $snapshot = is_array($audit->catalog_context_snapshot) ? $audit->catalog_context_snapshot : [];
        $counts = is_array($audit->peer_counts) ? $audit->peer_counts : [];
        $coverage = is_array($snapshot['relative_coverage'] ?? null) ? $snapshot['relative_coverage'] : [];

        return [
            ['label' => 'Concept', 'value' => $audit->concept_label ?: ($audit->concept_key ?: '—')],
            ['label' => 'Peer count', 'value' => (string) ($counts['concept'] ?? $coverage['concept_peer_count'] ?? 0)],
            ['label' => 'Budget-band population', 'value' => (string) ($counts['budget_band'] ?? 0)],
            ['label' => 'Relevant taxonomy coverage', 'value' => (string) ($counts['taxonomy_signature'] ?? 0)],
            ['label' => 'Intent coverage', 'value' => (string) ($counts['gift_intents'] ?? 0)],
            ['label' => 'Budget band', 'value' => filled($snapshot['price_band'] ?? null) ? $this->label((string) $snapshot['price_band']) : '—'],
            ['label' => 'Differentiation', 'value' => filled($snapshot['differentiation_strength'] ?? null) ? $this->label((string) $snapshot['differentiation_strength']) : '—'],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function metadata(ProductCurationAudit $audit): array
    {
        return [
            ['label' => 'Audit ID', 'value' => (string) $audit->id],
            ['label' => 'Run', 'value' => (string) $audit->run_id],
            ['label' => 'Completed at', 'value' => $audit->completed_at?->toDateTimeString() ?? '—'],
            ['label' => 'Model', 'value' => filled($audit->ai_model) ? (string) $audit->ai_model : '—'],
            ['label' => 'Evaluator version', 'value' => (string) ($audit->semantic_evaluator_version ?: '—')],
            ['label' => 'Prompt version', 'value' => (string) ($audit->prompt_version ?: '—')],
            ['label' => 'Scoring version', 'value' => (string) ($audit->scoring_version ?: '—')],
            ['label' => 'Context version', 'value' => (string) ($audit->context_version ?: '—')],
            ['label' => 'Evidence fingerprint', 'value' => (string) ($audit->evidence_fingerprint ?: '—')],
            ['label' => 'Semantic fingerprint', 'value' => (string) ($audit->semantic_fingerprint ?: '—')],
        ];
    }

    private function band(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }

        $bands = (array) config('catalog_curation.scoring_bands', []);

        if ($score >= (int) ($bands['strong'] ?? 80)) {
            return 'Strong';
        }

        if ($score >= (int) ($bands['moderate'] ?? 65)) {
            return 'Moderate';
        }

        return 'Weak';
    }

    private function factorLabel(string $key): string
    {
        return match ($key) {
            'recipient_desirability' => 'Recipient desirability',
            'thoughtfulness_emotional_potential' => 'Thoughtfulness',
            'uniqueness' => 'Uniqueness',
            'value_for_money' => 'Value for money',
            'visual_gifting_appeal' => 'Visual / gifting appeal',
            'practical_usefulness' => 'Practical usefulness',
            'product_vendor_confidence' => 'Product/vendor confidence',
            'social_media_shareability' => 'Social shareability',
            'saturation_novelty' => 'Concept novelty',
            'differentiation' => 'Differentiation',
            'budget_gap' => 'Budget gap',
            'taxonomy_gap' => 'Taxonomy coverage',
            'intents' => 'Intent coverage',
            'niche' => 'Niche contribution',
            default => $this->label($key),
        };
    }

    private function differenceActionLabel(string $action): string
    {
        return match ($action) {
            'add' => 'Added',
            'remove' => 'Removed',
            'retain' => 'Retained',
            'conflict' => 'Conflict',
            default => $this->label($action),
        };
    }

    private function strengthLabel(string $strength): string
    {
        return match ($strength) {
            'strong' => 'Strong',
            'medium' => 'Medium',
            'weak' => 'Weak',
            'unsupported' => 'Unsupported',
            default => $this->label($strength),
        };
    }

    private function strengthColor(?string $strength): string
    {
        return match ($strength) {
            'strong' => 'success',
            'medium' => 'warning',
            'weak' => 'gray',
            'unsupported' => 'danger',
            default => 'gray',
        };
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
            $flat = array_values(array_filter(Arr::flatten($value), fn (mixed $item): bool => $item !== null && $item !== ''));
            $preview = array_slice($flat, 0, 6);
            $label = implode(', ', array_map(fn (mixed $item): string => (string) $item, $preview));
            $hidden = count($flat) - count($preview);

            return $hidden > 0 ? $label.' +'.$hidden.' more' : $label;
        }

        return (string) $value;
    }

    private function label(?string $value): string
    {
        return filled($value) ? Str::of($value)->replace('_', ' ')->headline()->toString() : '—';
    }
}
