<?php

namespace App\Actions\PublicationReadiness;

use App\Actions\CatalogCandidate\ValidateProductTaxonomyClassificationAction;
use App\Actions\CatalogCuration\CaptureProductTaxonomySnapshotAction;
use App\Actions\Category\IsAcceptableMerchandisingCategoryAction;
use App\Actions\CuratedCatalog\DetectStaleCuratedTaxonomyProposalAction;
use App\Actions\LaunchPublication\QueryLaunchPublicationCandidatesAction;
use App\Actions\Product\AssessProductPublicationRequirementsAction;
use App\Actions\Product\ValidateProductTaxonomySemanticConflictsAction;
use App\CommercialSourcing\ValidatedProductTaxonomyClassification;
use App\CuratedCatalog\TaxonomySemanticConflict;
use App\Enums\PublicationReadinessDiagnosisCode;
use App\Enums\PublicationReadinessRemediation;
use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyClassificationWarningCode;
use App\Enums\TaxonomyDimension;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Product;
use App\PublicationReadiness\PublicationReadinessDiagnosis;

class DiagnosePublicationReadinessProductAction
{
    /**
     * @var array<string, int>
     */
    private const HUMAN_CAPS = [
        'categories' => 20,
        'occasions' => 50,
        'relationships' => 50,
        'recipient_types' => 50,
        'recipient_genders' => 1,
        'interests' => 50,
        'professions' => 50,
        'gift_types' => 50,
    ];

    public function __construct(
        private AssessProductPublicationRequirementsAction $assessPublication,
        private CaptureProductTaxonomySnapshotAction $captureSnapshot,
        private DetectStaleCuratedTaxonomyProposalAction $detectStale,
        private ValidateProductTaxonomyClassificationAction $validateTaxonomy,
        private ValidateProductTaxonomySemanticConflictsAction $semanticConflicts,
        private IsAcceptableMerchandisingCategoryAction $isAcceptableMerchandisingCategory,
        private QueryLaunchPublicationCandidatesAction $queryCandidates,
    ) {}

    /**
     * @return list<PublicationReadinessDiagnosis>
     */
    public function backlog(): array
    {
        $diagnoses = [];

        foreach ($this->queryCandidates->execute() as $product) {
            $diagnosis = $this->execute($product);

            if ($diagnosis instanceof PublicationReadinessDiagnosis) {
                $diagnoses[] = $diagnosis;
            }
        }

        return $diagnoses;
    }

    public function execute(Product $product): ?PublicationReadinessDiagnosis
    {
        $product = $product->fresh() ?? $product;
        $assessment = $this->assessPublication->execute($product);
        $blockers = $assessment['error_codes'];

        if ($blockers === []) {
            return null;
        }

        $snapshot = $this->captureSnapshot->execute($product);
        $before = [
            'classification_status' => $product->taxonomy_classification_status?->value,
            'primary_category_id' => $snapshot->primaryCategoryId,
            'category_ids' => $snapshot->categoryIds,
            'relationship_ids' => $snapshot->relationshipIds,
            'occasion_ids' => $snapshot->occasionIds,
            'interest_ids' => $snapshot->interestIds,
            'gift_type_ids' => $snapshot->giftTypeIds,
            'recipient_type_ids' => $snapshot->recipientTypeIds,
            'profession_ids' => $snapshot->professionIds,
            'stale_proposal' => $this->detectStale->execute($product),
        ];

        $missingPrimary = in_array('missing_primary_category', $blockers, true);
        $lifecycle = in_array('classification_not_publishable', $blockers, true);
        $status = $product->taxonomy_classification_status ?? TaxonomyClassificationStatus::None;
        $proposal = is_array($product->taxonomy_classification_proposal)
            ? $product->taxonomy_classification_proposal
            : [];
        $stale = $this->detectStale->execute($product);

        if ($this->isGiftCardFailure($status, $proposal, $missingPrimary)) {
            $suggested = $this->giftCardTaxonomy($proposal);

            return new PublicationReadinessDiagnosis(
                productId: (int) $product->id,
                title: (string) $product->name,
                blockers: $blockers,
                code: PublicationReadinessDiagnosisCode::HistoricalStaleState,
                remediation: $suggested === null
                    ? PublicationReadinessRemediation::LeaveBlocked
                    : PublicationReadinessRemediation::HumanClassify,
                reason: $suggested === null
                    ? 'Failed classification still has no honest merchandising category for this digital gift card.'
                    : 'Classification failed when Gift Cards was absent from the active taxonomy. Current evidence supports assigning the existing Gift Cards merchandising category without forcing recipient gaps.',
                before: $before,
                suggestedTaxonomy: $suggested,
            );
        }

        if ($stale && $lifecycle) {
            return new PublicationReadinessDiagnosis(
                productId: (int) $product->id,
                title: (string) $product->name,
                blockers: $blockers,
                code: PublicationReadinessDiagnosisCode::HistoricalStaleState,
                remediation: $status === TaxonomyClassificationStatus::Failed
                    ? PublicationReadinessRemediation::RetryClassification
                    : PublicationReadinessRemediation::LeaveBlocked,
                reason: 'The stored classification proposal is stale relative to current version, title, or relationship hints.',
                before: $before,
            );
        }

        if ($status === TaxonomyClassificationStatus::Review && $proposal !== []) {
            $autoValidated = $this->validateTaxonomy->execute($proposal);
            $validated = $this->validateTaxonomy->execute($proposal, self::HUMAN_CAPS);

            if ($validated->primaryCategoryId === null) {
                return $this->ambiguity(
                    $product,
                    $blockers,
                    $before,
                    'The stored proposal no longer resolves to an active merchandising primary category.',
                );
            }

            $conflicts = $this->semanticConflicts->execute($validated);

            if ($conflicts !== []) {
                $resolved = $this->dropConflictingTrustedHints($proposal, $validated, $conflicts);

                if ($resolved !== null) {
                    return new PublicationReadinessDiagnosis(
                        productId: (int) $product->id,
                        title: (string) $product->name,
                        blockers: $blockers,
                        code: PublicationReadinessDiagnosisCode::ClassificationLifecycleOnly,
                        remediation: PublicationReadinessRemediation::HumanClassify,
                        reason: 'Stored REVIEW taxonomy is otherwise valid, but trusted wishlist relationship hints conflict with an evidence-based Occasion. Drop the conflicting hints and keep the remaining evidence-based assignments.',
                        before: $before,
                        suggestedTaxonomy: $resolved,
                    );
                }

                return $this->ambiguity(
                    $product,
                    $blockers,
                    $before,
                    'Stored REVIEW taxonomy has semantic conflicts that cannot be resolved from current evidence.',
                );
            }

            if ($this->isUnresolvedCategoryGuess($proposal, $validated)) {
                return $this->ambiguity(
                    $product,
                    $blockers,
                    $before,
                    'Primary category confidence is below the keep threshold and current evidence does not identify the merchandising family.',
                );
            }

            $lifecycleOnly = $missingPrimary && $lifecycle
                ? PublicationReadinessDiagnosisCode::ClassificationLifecycleOnly
                : ($missingPrimary
                    ? PublicationReadinessDiagnosisCode::MissingPrimaryCategoryOnly
                    : PublicationReadinessDiagnosisCode::ClassificationLifecycleOnly);

            if ($autoValidated->rejectedIds === [] && $autoValidated->primaryCategoryId !== null) {
                return new PublicationReadinessDiagnosis(
                    productId: (int) $product->id,
                    title: (string) $product->name,
                    blockers: $blockers,
                    code: $lifecycleOnly,
                    remediation: PublicationReadinessRemediation::ApproveStoredProposal,
                    reason: 'REVIEW left a current, conflict-free proposal unapplied, so publication sees both a missing primary category and an unpublishable classification status.',
                    before: $before,
                );
            }

            return new PublicationReadinessDiagnosis(
                productId: (int) $product->id,
                title: (string) $product->name,
                blockers: $blockers,
                code: $lifecycleOnly,
                remediation: PublicationReadinessRemediation::HumanClassify,
                reason: 'The stored REVIEW proposal is merchandising-valid, but inactive or over-cap IDs prevent unchanged approval. Apply the accepted remainder through human classification.',
                before: $before,
                suggestedTaxonomy: $this->taxonomyArray($validated),
            );
        }

        if ($missingPrimary && ! $lifecycle) {
            return new PublicationReadinessDiagnosis(
                productId: (int) $product->id,
                title: (string) $product->name,
                blockers: $blockers,
                code: PublicationReadinessDiagnosisCode::MissingPrimaryCategoryOnly,
                remediation: PublicationReadinessRemediation::LeaveBlocked,
                reason: 'Classification is publishable but no primary merchandising category is assigned, and none can be inferred confidently.',
                before: $before,
            );
        }

        if ($lifecycle && ! $missingPrimary) {
            return new PublicationReadinessDiagnosis(
                productId: (int) $product->id,
                title: (string) $product->name,
                blockers: $blockers,
                code: PublicationReadinessDiagnosisCode::ClassificationLifecycleOnly,
                remediation: PublicationReadinessRemediation::LeaveBlocked,
                reason: 'A primary category is assigned but classification status still blocks publication.',
                before: $before,
            );
        }

        return new PublicationReadinessDiagnosis(
            productId: (int) $product->id,
            title: (string) $product->name,
            blockers: $blockers,
            code: PublicationReadinessDiagnosisCode::Both,
            remediation: PublicationReadinessRemediation::LeaveBlocked,
            reason: 'Primary category and classification lifecycle are independently blocked.',
            before: $before,
        );
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function isGiftCardFailure(
        TaxonomyClassificationStatus $status,
        array $proposal,
        bool $missingPrimary,
    ): bool {
        if ($status !== TaxonomyClassificationStatus::Failed || ! $missingPrimary) {
            return false;
        }

        $reasons = array_values(array_filter(
            (array) ($proposal['review_reasons'] ?? []),
            fn (mixed $reason): bool => is_string($reason),
        ));

        $hasBlockingGap = in_array(TaxonomyClassificationWarningCode::TaxonomyGapBlocking->value, $reasons, true)
            || in_array(TaxonomyClassificationWarningCode::MissingPrimaryCategory->value, $reasons, true);

        if (! $hasBlockingGap) {
            return false;
        }

        return $this->proposalHasGiftCardTypes($proposal) || $this->gapSuggestsGiftCard($proposal);
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>|null
     */
    private function giftCardTaxonomy(array $proposal): ?array
    {
        $category = $this->giftCardCategory();

        if (! $category instanceof Category) {
            return null;
        }

        $validated = $this->validateTaxonomy->execute([
            ...$proposal,
            'primary_category_id' => $category->id,
            'category_ids' => [$category->id],
        ]);

        if ($validated->primaryCategoryId === null || $validated->rejectedIds !== []) {
            return null;
        }

        $conflicts = $this->semanticConflicts->execute($validated);

        if ($conflicts !== []) {
            return null;
        }

        return $this->taxonomyArray($validated);
    }

    private function giftCardCategory(): ?Category
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('slug', 'gift-cards-vouchers')
                    ->orWhere('slug', 'like', 'gift-card%');
            })
            ->orderByRaw('parent_id is not null')
            ->orderBy('id')
            ->get();

        foreach ($categories as $category) {
            if ($this->isAcceptableMerchandisingCategory->execute((int) $category->id)) {
                return $category;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function proposalHasGiftCardTypes(array $proposal): bool
    {
        $ids = array_values(array_filter(
            array_map('intval', (array) ($proposal['gift_type_ids'] ?? [])),
            fn (int $id): bool => $id > 0,
        ));

        if ($ids === []) {
            return false;
        }

        return GiftType::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->whereIn('slug', ['gift-cards', 'digital-instant-gifts'])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function gapSuggestsGiftCard(array $proposal): bool
    {
        $gap = is_array($proposal['taxonomy_gap'] ?? null) ? $proposal['taxonomy_gap'] : [];
        $haystack = strtolower(trim(implode(' ', array_filter([
            is_string($gap['suggested_concept'] ?? null) ? $gap['suggested_concept'] : '',
            is_string($gap['explanation'] ?? null) ? $gap['explanation'] : '',
        ]))));

        return $haystack !== '' && str_contains($haystack, 'gift card');
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  list<TaxonomySemanticConflict>  $conflicts
     * @return array<string, mixed>|null
     */
    private function dropConflictingTrustedHints(
        array $proposal,
        ValidatedProductTaxonomyClassification $validated,
        array $conflicts,
    ): ?array {
        $hintIds = array_values(array_filter(
            array_map('intval', (array) ($proposal['source_relationship_hint_ids'] ?? [])),
            fn (int $id): bool => $id > 0,
        ));

        if ($hintIds === []) {
            return null;
        }

        $drop = [];

        foreach ($conflicts as $conflict) {
            foreach ([
                [$conflict->leftDimension, $conflict->leftId],
                [$conflict->rightDimension, $conflict->rightId],
            ] as [$dimension, $id]) {
                if ($dimension === TaxonomyDimension::Relationship && in_array((int) $id, $hintIds, true)) {
                    $drop[] = (int) $id;
                }
            }
        }

        $drop = array_values(array_unique($drop));

        if ($drop === []) {
            return null;
        }

        $remaining = array_values(array_filter(
            $validated->relationshipIds,
            fn (int $id): bool => ! in_array($id, $drop, true),
        ));
        $retry = $this->validateTaxonomy->execute([
            'primary_category_id' => $validated->primaryCategoryId,
            'category_ids' => $validated->categoryIds,
            'relationship_ids' => $remaining,
            'occasion_ids' => $validated->occasionIds,
            'interest_ids' => $validated->interestIds,
            'gift_type_ids' => $validated->giftTypeIds,
            'recipient_type_ids' => $validated->recipientTypeIds,
            'profession_ids' => $validated->professionIds,
        ], self::HUMAN_CAPS);

        if ($retry->primaryCategoryId === null || $retry->rejectedIds !== []) {
            return null;
        }

        if ($this->semanticConflicts->execute($retry) !== []) {
            return null;
        }

        return $this->taxonomyArray($retry);
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function isUnresolvedCategoryGuess(array $proposal, ValidatedProductTaxonomyClassification $validated): bool
    {
        $confidence = is_array($proposal['confidence'] ?? null) ? $proposal['confidence'] : [];
        $primaryConfidence = is_numeric($confidence['primary_category'] ?? null)
            ? (float) $confidence['primary_category']
            : null;
        $keepMin = (float) config('curated_catalog.taxonomy_classification.thresholds.optional_keep_min', 0.60);

        if ($primaryConfidence === null || $primaryConfidence >= $keepMin) {
            return false;
        }

        $reasoning = strtolower((string) data_get($proposal, 'reasoning_summary.primary_category', ''));

        return str_contains($reasoning, 'does not reveal')
            || str_contains($reasoning, 'contents')
            || $validated->primaryCategoryId === null;
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string, mixed>  $before
     */
    private function ambiguity(
        Product $product,
        array $blockers,
        array $before,
        string $reason,
    ): PublicationReadinessDiagnosis {
        return new PublicationReadinessDiagnosis(
            productId: (int) $product->id,
            title: (string) $product->name,
            blockers: $blockers,
            code: PublicationReadinessDiagnosisCode::TaxonomyAmbiguity,
            remediation: PublicationReadinessRemediation::LeaveBlocked,
            reason: $reason,
            before: $before,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function taxonomyArray(ValidatedProductTaxonomyClassification $taxonomy): array
    {
        return [
            'primary_category_id' => $taxonomy->primaryCategoryId,
            'category_ids' => $taxonomy->categoryIds,
            'relationship_ids' => $taxonomy->relationshipIds,
            'occasion_ids' => $taxonomy->occasionIds,
            'interest_ids' => $taxonomy->interestIds,
            'gift_type_ids' => $taxonomy->giftTypeIds,
            'recipient_type_ids' => $taxonomy->recipientTypeIds,
            'profession_ids' => $taxonomy->professionIds,
        ];
    }
}
