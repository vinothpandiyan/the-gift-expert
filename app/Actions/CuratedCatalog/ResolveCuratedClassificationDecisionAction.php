<?php

namespace App\Actions\CuratedCatalog;

use App\Actions\Product\ValidateProductTaxonomySemanticConflictsAction;
use App\CommercialSourcing\ValidatedProductTaxonomyClassification;
use App\CuratedCatalog\CuratedClassificationConfidence;
use App\CuratedCatalog\CuratedClassificationDecision;
use App\CuratedCatalog\CuratedClassificationProposal;
use App\CuratedCatalog\CuratedTaxonomyGap;
use App\CuratedCatalog\TaxonomySemanticConflict;
use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyClassificationWarningCode;
use App\Enums\TaxonomyDimension;

class ResolveCuratedClassificationDecisionAction
{
    public function __construct(
        private ValidateProductTaxonomySemanticConflictsAction $semanticConflicts,
    ) {}

    /**
     * @param  list<int>  $sourceRelationshipHintIds
     * @param  list<string>  $incomingWarnings
     */
    public function execute(
        ValidatedProductTaxonomyClassification $taxonomy,
        CuratedClassificationConfidence $confidence,
        CuratedTaxonomyGap $taxonomyGap,
        array $sourceRelationshipHintIds,
        array $incomingWarnings = [],
        ?string $name = null,
        ?string $shortDescription = null,
        ?string $description = null,
        ?string $brand = null,
        ?array $reasoningSummary = null,
    ): CuratedClassificationDecision {
        $warnings = $incomingWarnings;
        $reviewReasons = [];
        $version = (int) config('curated_catalog.taxonomy_classification.version', 1);
        $taxonomyGap = $taxonomyGap->withResolvedSeverity($taxonomy->primaryCategoryId);

        if ($taxonomy->primaryCategoryId === null) {
            $warnings[] = TaxonomyClassificationWarningCode::MissingPrimaryCategory->value;
            $this->recordTaxonomyGap($taxonomyGap, $warnings, $reviewReasons);
            $reviewReasons[] = TaxonomyClassificationWarningCode::MissingPrimaryCategory->value;
            $proposal = $this->proposal(
                $taxonomy,
                $confidence,
                $taxonomyGap,
                $sourceRelationshipHintIds,
                $warnings,
                $reviewReasons,
                $version,
                $name,
                $shortDescription,
                $description,
                $brand,
                $reasoningSummary,
            );

            return new CuratedClassificationDecision(
                status: TaxonomyClassificationStatus::Failed,
                proposal: $proposal,
                warnings: $proposal->warnings,
                reviewReasons: $proposal->reviewReasons,
                taxonomyGap: $taxonomyGap,
            );
        }

        $taxonomy = $this->dropLowConfidenceOptionals($taxonomy, $confidence, $warnings);
        $taxonomy = $this->handleGiftTypes($taxonomy, $confidence, $warnings);
        $taxonomy = $this->addTrustedHints($taxonomy, $sourceRelationshipHintIds, $warnings, $reviewReasons);

        $taxonomy = $this->resolveAiAddedConflicts(
            $taxonomy,
            $sourceRelationshipHintIds,
            $warnings,
            $reviewReasons,
        );

        $remainingConflicts = $this->semanticConflicts->execute($taxonomy);

        if ($this->hasTrustedHintConflict($remainingConflicts, $sourceRelationshipHintIds)) {
            $warnings[] = TaxonomyClassificationWarningCode::TrustedSourceSemanticConflict->value;
            $reviewReasons[] = TaxonomyClassificationWarningCode::TrustedSourceSemanticConflict->value;
        }

        $this->recordTaxonomyGap($taxonomyGap, $warnings, $reviewReasons);

        $categoryThreshold = (float) config(
            'curated_catalog.taxonomy_classification.thresholds.primary_category_auto_accept',
            0.85,
        );

        if ($confidence->primaryCategory === null || $confidence->primaryCategory < $categoryThreshold) {
            $warnings[] = TaxonomyClassificationWarningCode::LowPrimaryCategoryConfidence->value;
            $reviewReasons[] = TaxonomyClassificationWarningCode::LowPrimaryCategoryConfidence->value;
        }

        if (! $confidence->structurallyValid) {
            $warnings[] = TaxonomyClassificationWarningCode::MalformedPartialTaxonomy->value;
            $reviewReasons[] = TaxonomyClassificationWarningCode::MalformedPartialTaxonomy->value;
        }

        $reviewReasons = array_values(array_unique($reviewReasons));
        $warnings = array_values(array_unique($warnings));
        $status = $reviewReasons === []
            ? TaxonomyClassificationStatus::AiAccepted
            : TaxonomyClassificationStatus::Review;

        $proposal = $this->proposal(
            $taxonomy,
            $confidence,
            $taxonomyGap,
            $sourceRelationshipHintIds,
            $warnings,
            $reviewReasons,
            $version,
            $name,
            $shortDescription,
            $description,
            $brand,
            $reasoningSummary,
        );

        return new CuratedClassificationDecision(
            status: $status,
            proposal: $proposal,
            warnings: $warnings,
            reviewReasons: $reviewReasons,
            taxonomyGap: $taxonomyGap,
        );
    }

    /**
     * @param  list<string>  $warnings
     */
    private function dropLowConfidenceOptionals(
        ValidatedProductTaxonomyClassification $taxonomy,
        CuratedClassificationConfidence $confidence,
        array &$warnings,
    ): ValidatedProductTaxonomyClassification {
        $min = (float) config('curated_catalog.taxonomy_classification.thresholds.optional_keep_min', 0.60);
        $interestIds = $taxonomy->interestIds;
        $occasionIds = $taxonomy->occasionIds;
        $recipientTypeIds = $taxonomy->recipientTypeIds;
        $professionIds = $taxonomy->professionIds;

        if ($interestIds !== [] && $confidence->interests !== null && $confidence->interests < $min) {
            $interestIds = [];
            $warnings[] = TaxonomyClassificationWarningCode::AiOptionalTaxonomyDropped->value;
        }

        if ($occasionIds !== [] && $confidence->occasions !== null && $confidence->occasions < $min) {
            $occasionIds = [];
            $warnings[] = TaxonomyClassificationWarningCode::AiOptionalTaxonomyDropped->value;
        }

        if ($recipientTypeIds !== [] && $confidence->recipientTypes !== null && $confidence->recipientTypes < $min) {
            $recipientTypeIds = [];
            $warnings[] = TaxonomyClassificationWarningCode::AiOptionalTaxonomyDropped->value;
        }

        if ($professionIds !== [] && $confidence->professions !== null && $confidence->professions < $min) {
            $professionIds = [];
            $warnings[] = TaxonomyClassificationWarningCode::AiOptionalTaxonomyDropped->value;
        }

        return $taxonomy->with(
            interestIds: $interestIds,
            occasionIds: $occasionIds,
            recipientTypeIds: $recipientTypeIds,
            professionIds: $professionIds,
        );
    }

    /**
     * @param  list<string>  $warnings
     */
    private function handleGiftTypes(
        ValidatedProductTaxonomyClassification $taxonomy,
        CuratedClassificationConfidence $confidence,
        array &$warnings,
    ): ValidatedProductTaxonomyClassification {
        if ($taxonomy->giftTypeIds === []) {
            return $taxonomy;
        }

        $threshold = (float) config('curated_catalog.taxonomy_classification.thresholds.gift_type_auto_accept', 0.80);

        if ($confidence->giftTypes !== null && $confidence->giftTypes >= $threshold) {
            return $taxonomy;
        }

        $warnings[] = TaxonomyClassificationWarningCode::LowGiftTypeConfidence->value;

        return $taxonomy->with(giftTypeIds: []);
    }

    /**
     * @param  list<int>  $sourceRelationshipHintIds
     * @param  list<string>  $warnings
     * @param  list<string>  $reviewReasons
     */
    private function addTrustedHints(
        ValidatedProductTaxonomyClassification $taxonomy,
        array $sourceRelationshipHintIds,
        array &$warnings,
        array &$reviewReasons,
    ): ValidatedProductTaxonomyClassification {
        $relationshipIds = $taxonomy->relationshipIds;

        foreach ($sourceRelationshipHintIds as $hintId) {
            if (in_array($hintId, $relationshipIds, true)) {
                continue;
            }

            $trial = $taxonomy->with(relationshipIds: array_values(array_unique([...$relationshipIds, $hintId])));
            $conflicts = $this->semanticConflicts->execute($trial);
            $involvesHint = $this->conflictsInvolve(
                $conflicts,
                TaxonomyDimension::Relationship,
                $hintId,
            );

            if ($involvesHint) {
                $warnings[] = TaxonomyClassificationWarningCode::TrustedHintIncompatible->value;
                $reviewReasons[] = TaxonomyClassificationWarningCode::TrustedHintIncompatible->value;
                $reviewReasons[] = TaxonomyClassificationWarningCode::MissingExpectedRelationship->value;

                continue;
            }

            $relationshipIds[] = $hintId;
            $warnings[] = TaxonomyClassificationWarningCode::TrustedHintAdded->value;
        }

        return $taxonomy->with(relationshipIds: array_values(array_unique($relationshipIds)));
    }

    /**
     * Advisory gaps are stored for taxonomy planning and do not block AUTO-ACCEPT.
     * Blocking gaps remain a review reason.
     *
     * @param  list<string>  $warnings
     * @param  list<string>  $reviewReasons
     */
    private function recordTaxonomyGap(
        CuratedTaxonomyGap $taxonomyGap,
        array &$warnings,
        array &$reviewReasons,
    ): void {
        if (! $taxonomyGap->detected) {
            return;
        }

        if ($taxonomyGap->isBlocking()) {
            $warnings[] = TaxonomyClassificationWarningCode::TaxonomyGapBlocking->value;
            $reviewReasons[] = TaxonomyClassificationWarningCode::TaxonomyGapBlocking->value;

            return;
        }

        $warnings[] = TaxonomyClassificationWarningCode::TaxonomyGapAdvisory->value;
    }

    /**
     * @param  list<int>  $sourceRelationshipHintIds
     * @param  list<string>  $warnings
     * @param  list<string>  $reviewReasons
     */
    private function resolveAiAddedConflicts(
        ValidatedProductTaxonomyClassification $taxonomy,
        array $sourceRelationshipHintIds,
        array &$warnings,
        array &$reviewReasons,
    ): ValidatedProductTaxonomyClassification {
        $guard = 0;

        while ($guard < 10) {
            $guard++;
            $conflicts = $this->semanticConflicts->execute($taxonomy);

            if ($conflicts === []) {
                return $taxonomy;
            }

            $droppable = null;

            foreach ($conflicts as $conflict) {
                if ($this->conflictInvolvesTrustedHint($conflict, $sourceRelationshipHintIds)) {
                    continue;
                }

                $droppable = $this->lowerValueAssignment($conflict, $taxonomy);

                if ($droppable !== null) {
                    break;
                }
            }

            if ($droppable === null) {
                return $taxonomy;
            }

            $taxonomy = $this->dropAssignment($taxonomy, $droppable['dimension'], $droppable['id']);
            $warnings[] = TaxonomyClassificationWarningCode::AiSecondaryAssignmentRemoved->value;
        }

        return $taxonomy;
    }

    /**
     * @param  list<int>  $sourceRelationshipHintIds
     */
    private function conflictInvolvesTrustedHint(TaxonomySemanticConflict $conflict, array $sourceRelationshipHintIds): bool
    {
        if ($conflict->leftDimension === TaxonomyDimension::Relationship
            && in_array($conflict->leftId, $sourceRelationshipHintIds, true)) {
            return true;
        }

        return $conflict->rightDimension === TaxonomyDimension::Relationship
            && in_array($conflict->rightId, $sourceRelationshipHintIds, true);
    }

    /**
     * @param  list<TaxonomySemanticConflict>  $conflicts
     * @param  list<int>  $sourceRelationshipHintIds
     */
    private function hasTrustedHintConflict(array $conflicts, array $sourceRelationshipHintIds): bool
    {
        foreach ($conflicts as $conflict) {
            if ($this->conflictInvolvesTrustedHint($conflict, $sourceRelationshipHintIds)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<TaxonomySemanticConflict>  $conflicts
     */
    private function conflictsInvolve(array $conflicts, TaxonomyDimension $dimension, int $id): bool
    {
        foreach ($conflicts as $conflict) {
            if ($conflict->leftDimension === $dimension && $conflict->leftId === $id) {
                return true;
            }

            if ($conflict->rightDimension === $dimension && $conflict->rightId === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{dimension: TaxonomyDimension, id: int}|null
     */
    private function lowerValueAssignment(
        TaxonomySemanticConflict $conflict,
        ValidatedProductTaxonomyClassification $taxonomy,
    ): ?array {
        $rank = function (TaxonomyDimension $dimension): int {
            return match ($dimension) {
                TaxonomyDimension::Occasion => 10,
                TaxonomyDimension::GiftType => 20,
                TaxonomyDimension::Interest => 30,
                TaxonomyDimension::Profession => 40,
                TaxonomyDimension::RecipientType => 50,
                TaxonomyDimension::Relationship => 90,
                TaxonomyDimension::Category => 100,
            };
        };

        $leftRank = $rank($conflict->leftDimension);
        $rightRank = $rank($conflict->rightDimension);

        if ($leftRank === $rightRank) {
            return [
                'dimension' => $conflict->rightDimension,
                'id' => $conflict->rightId,
            ];
        }

        if ($leftRank < $rightRank) {
            return [
                'dimension' => $conflict->leftDimension,
                'id' => $conflict->leftId,
            ];
        }

        return [
            'dimension' => $conflict->rightDimension,
            'id' => $conflict->rightId,
        ];
    }

    private function dropAssignment(
        ValidatedProductTaxonomyClassification $taxonomy,
        TaxonomyDimension $dimension,
        int $id,
    ): ValidatedProductTaxonomyClassification {
        $without = function (array $ids) use ($id): array {
            return array_values(array_filter($ids, fn (int $candidate): bool => $candidate !== $id));
        };

        return match ($dimension) {
            TaxonomyDimension::Occasion => $taxonomy->with(occasionIds: $without($taxonomy->occasionIds)),
            TaxonomyDimension::GiftType => $taxonomy->with(giftTypeIds: $without($taxonomy->giftTypeIds)),
            TaxonomyDimension::Interest => $taxonomy->with(interestIds: $without($taxonomy->interestIds)),
            TaxonomyDimension::Profession => $taxonomy->with(professionIds: $without($taxonomy->professionIds)),
            TaxonomyDimension::RecipientType => $taxonomy->with(recipientTypeIds: $without($taxonomy->recipientTypeIds)),
            TaxonomyDimension::Relationship => $taxonomy->with(relationshipIds: $without($taxonomy->relationshipIds)),
            TaxonomyDimension::Category => $taxonomy,
        };
    }

    /**
     * @param  list<int>  $sourceRelationshipHintIds
     * @param  list<string>  $warnings
     * @param  list<string>  $reviewReasons
     * @param  array<string, string>|null  $reasoningSummary
     */
    private function proposal(
        ValidatedProductTaxonomyClassification $taxonomy,
        CuratedClassificationConfidence $confidence,
        CuratedTaxonomyGap $taxonomyGap,
        array $sourceRelationshipHintIds,
        array $warnings,
        array $reviewReasons,
        int $version,
        ?string $name,
        ?string $shortDescription,
        ?string $description,
        ?string $brand,
        ?array $reasoningSummary,
    ): CuratedClassificationProposal {
        return new CuratedClassificationProposal(
            taxonomy: $taxonomy,
            confidence: $confidence,
            reasoningSummary: is_array($reasoningSummary) ? $reasoningSummary : [],
            taxonomyGap: $taxonomyGap,
            sourceRelationshipHintIds: $sourceRelationshipHintIds,
            warnings: array_values(array_unique($warnings)),
            reviewReasons: array_values(array_unique($reviewReasons)),
            classificationVersion: $version,
            name: $name,
            shortDescription: $shortDescription,
            description: $description,
            brand: $brand,
        );
    }
}
