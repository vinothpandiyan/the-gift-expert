<?php

namespace App\Actions\CatalogCuration;

use App\Actions\Product\ValidateProductTaxonomySemanticConflictsAction;
use App\CatalogCuration\ProductCurationEvidence;
use App\CommercialSourcing\ValidatedProductTaxonomyClassification;
use App\Enums\TaxonomyDimension;

class BuildCurationTaxonomyDifferencesAction
{
    public function __construct(
        private ValidateProductTaxonomySemanticConflictsAction $validateHardConflicts,
    ) {}

    /**
     * @param  array<string, mixed>  $semantic
     * @return list<array<string, mixed>>
     */
    public function execute(ProductCurationEvidence $evidence, array $semantic): array
    {
        $differences = [];

        foreach (['relationships', 'occasions', 'interests', 'gift_types'] as $dimension) {
            $currentIds = array_map('intval', array_column($evidence->taxonomy[$dimension] ?? [], 'id'));
            $evaluatedIds = [];

            foreach ($semantic['current_taxonomy_evaluations'][$dimension] ?? [] as $fit) {
                $evaluatedIds[] = (int) ($fit['id'] ?? 0);
                $strength = $fit['strength'] ?? null;

                if (! in_array($strength, ['medium', 'weak'], true)) {
                    continue;
                }

                $severity = $this->currentFitSeverity(
                    $dimension,
                    $strength,
                    ($fit['misleading_on_targeted_landing_page'] ?? false) === true,
                );

                $differences[] = [
                    'cause' => "current_{$this->singular($dimension)}_{$strength}",
                    'dimension' => $dimension,
                    'action' => $strength === 'weak' ? 'remove' : 'retain',
                    'current_status' => 'assigned',
                    'taxonomy' => $this->taxonomy($fit),
                    'audit_strength' => $strength,
                    'severity' => $severity,
                    'forces_human_review' => in_array($severity, ['material', 'blocking'], true),
                    'hard_rule_violation' => false,
                    'misleading_on_targeted_landing_page' => ($fit['misleading_on_targeted_landing_page'] ?? false) === true,
                    'reason' => $fit['reason'],
                ];
            }

            foreach (array_diff($currentIds, $evaluatedIds) as $missingId) {
                $assignment = collect($evidence->taxonomy[$dimension] ?? [])->firstWhere('id', $missingId);

                if (! is_array($assignment)) {
                    continue;
                }

                $differences[] = [
                    'cause' => 'missing_current_assignment_evaluation',
                    'dimension' => $dimension,
                    'action' => 'evaluate',
                    'current_status' => 'assigned',
                    'taxonomy' => $this->taxonomy($assignment),
                    'audit_strength' => null,
                    'severity' => 'material',
                    'forces_human_review' => true,
                    'hard_rule_violation' => false,
                    'misleading_on_targeted_landing_page' => null,
                    'reason' => 'The semantic evaluator did not assess this current assignment.',
                ];
            }

            foreach ($semantic['taxonomy_suggestions'][$dimension] ?? [] as $fit) {
                if (! in_array((int) ($fit['id'] ?? 0), $currentIds, true)
                    && in_array($fit['strength'] ?? null, ['medium', 'strong'], true)) {
                    $differences[] = [
                        'cause' => "audit_only_{$this->singular($dimension)}",
                        'dimension' => $dimension,
                        'action' => 'add',
                        'current_status' => 'not_assigned',
                        'taxonomy' => $this->taxonomy($fit),
                        'audit_strength' => $fit['strength'],
                        'severity' => 'advisory',
                        'forces_human_review' => false,
                        'hard_rule_violation' => false,
                        'misleading_on_targeted_landing_page' => null,
                        'reason' => $fit['reason'] ?? 'The audit identified an additional possible fit.',
                    ];
                }
            }
        }

        foreach ($this->hardConflicts($evidence) as $conflict) {
            $differences[] = $conflict;
        }

        return $differences;
    }

    private function currentFitSeverity(string $dimension, string $strength, bool $misleading): string
    {
        if ($strength === 'medium') {
            return 'advisory';
        }

        if ($dimension === 'gift_types' || $misleading) {
            return 'material';
        }

        return 'advisory';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function hardConflicts(ProductCurationEvidence $evidence): array
    {
        $classification = new ValidatedProductTaxonomyClassification(
            primaryCategoryId: null,
            categoryIds: [],
            occasionIds: $this->ids($evidence, 'occasions'),
            relationshipIds: $this->ids($evidence, 'relationships'),
            recipientTypeIds: $this->ids($evidence, 'recipient_types'),
            recipientGenderIds: $this->ids($evidence, 'recipient_genders'),
            interestIds: $this->ids($evidence, 'interests'),
            professionIds: $this->ids($evidence, 'professions'),
            giftTypeIds: $this->ids($evidence, 'gift_types'),
            exceptionCodes: [],
            rejectedIds: [],
        );

        return array_map(function ($conflict) use ($evidence): array {
            $left = $this->assignedTaxonomy($evidence, $conflict->leftDimension, $conflict->leftId);
            $right = $this->assignedTaxonomy($evidence, $conflict->rightDimension, $conflict->rightId);

            return [
                'cause' => 'hard_applicability_conflict',
                'dimension' => 'applicability',
                'action' => 'resolve',
                'current_status' => 'assigned',
                'taxonomy' => $left,
                'conflicting_taxonomy' => $right,
                'audit_strength' => null,
                'severity' => 'blocking',
                'forces_human_review' => true,
                'hard_rule_violation' => true,
                'misleading_on_targeted_landing_page' => null,
                'reason' => sprintf(
                    '%s %s conflicts with %s %s under an authoritative applicability rule.',
                    $conflict->leftDimension->getLabel(),
                    $left['name'],
                    $conflict->rightDimension->getLabel(),
                    $right['name'],
                ),
            ];
        }, $this->validateHardConflicts->execute($classification));
    }

    /**
     * @return list<int>
     */
    private function ids(ProductCurationEvidence $evidence, string $dimension): array
    {
        return array_values(array_map('intval', array_column($evidence->taxonomy[$dimension] ?? [], 'id')));
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    private function assignedTaxonomy(
        ProductCurationEvidence $evidence,
        TaxonomyDimension $dimension,
        int $id,
    ): array {
        $key = match ($dimension) {
            TaxonomyDimension::Relationship => 'relationships',
            TaxonomyDimension::Occasion => 'occasions',
            TaxonomyDimension::RecipientType => 'recipient_types',
            TaxonomyDimension::RecipientGender => 'recipient_genders',
            TaxonomyDimension::Interest => 'interests',
            TaxonomyDimension::Profession => 'professions',
            TaxonomyDimension::GiftType => 'gift_types',
            TaxonomyDimension::Category => 'categories',
        };
        $row = collect($evidence->taxonomy[$key] ?? [])->firstWhere('id', $id);

        return is_array($row)
            ? $this->taxonomy($row)
            : ['id' => $id, 'name' => $dimension->value." #{$id}", 'slug' => (string) $id];
    }

    private function singular(string $dimension): string
    {
        return match ($dimension) {
            'relationships' => 'relationship',
            'occasions' => 'occasion',
            'interests' => 'interest',
            'gift_types' => 'gift_type',
            default => $dimension,
        };
    }

    /**
     * @param  array<string, mixed>  $fit
     * @return array{id: int, name: string, slug: string}
     */
    private function taxonomy(array $fit): array
    {
        return [
            'id' => (int) $fit['id'],
            'name' => (string) $fit['name'],
            'slug' => (string) $fit['slug'],
        ];
    }
}
