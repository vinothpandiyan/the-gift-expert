<?php

namespace App\Actions\CuratedCatalog;

use App\Actions\CatalogCandidate\ValidateProductTaxonomyClassificationAction;
use App\Actions\Product\ApplyProductTaxonomyClassificationAction;
use App\Actions\Product\NormalizeProductCategoryAssignmentsAction;
use App\Actions\Product\ValidateProductTaxonomySemanticConflictsAction;
use App\CuratedCatalog\TaxonomySemanticConflict;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ApproveCuratedTaxonomyProposalAction
{
    public function __construct(
        private DetectStaleCuratedTaxonomyProposalAction $detectStale,
        private ValidateProductTaxonomyClassificationAction $validateTaxonomy,
        private NormalizeProductCategoryAssignmentsAction $normalizeCategories,
        private ValidateProductTaxonomySemanticConflictsAction $semanticConflicts,
        private ApplyProductTaxonomyClassificationAction $applyTaxonomy,
    ) {}

    public function execute(Product $product, User $user): Product
    {
        $product = $product->fresh() ?? $product;

        if (! $this->canApprove($product)) {
            throw ValidationException::withMessages([
                'taxonomy' => ['This gift does not have an AI proposal waiting for approval.'],
            ]);
        }

        if ($this->detectStale->execute($product)) {
            throw ValidationException::withMessages([
                'taxonomy' => ['This AI proposal is stale because taxonomy version, source title, or relationship hints changed. Reclassify before approving.'],
            ]);
        }

        $proposal = $product->taxonomy_classification_proposal;

        if (! is_array($proposal) || $proposal === []) {
            throw ValidationException::withMessages([
                'taxonomy' => ['This gift has no stored AI taxonomy proposal to approve.'],
            ]);
        }

        $validated = $this->validateTaxonomy->execute($proposal);

        if ($validated->primaryCategoryId === null) {
            throw ValidationException::withMessages([
                'taxonomy' => ['The stored proposal is no longer valid against the current taxonomy. Reclassify before approving.'],
            ]);
        }

        if ($validated->rejectedIds !== []) {
            throw ValidationException::withMessages([
                'taxonomy' => ['The stored proposal includes taxonomy that is no longer active. Reclassify before approving.'],
            ]);
        }

        try {
            $normalized = $this->normalizeCategories->execute($validated->primaryCategoryId);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'taxonomy' => ['The proposed primary category is not an active merchandising category. Reclassify before approving.'],
            ]);
        }

        $taxonomy = $validated->with(
            primaryCategoryId: $normalized->primaryCategoryId,
            categoryIds: $normalized->categoryIds,
        );

        $conflicts = $this->semanticConflicts->execute($taxonomy);

        if ($conflicts !== []) {
            throw ValidationException::withMessages([
                'taxonomy' => $this->conflictMessages($conflicts),
            ]);
        }

        return DB::transaction(function () use ($product, $user, $taxonomy): Product {
            $fresh = Product::query()->lockForUpdate()->findOrFail($product->id);

            if (! $this->canApprove($fresh)) {
                throw ValidationException::withMessages([
                    'taxonomy' => ['This gift does not have an AI proposal waiting for approval.'],
                ]);
            }

            $applied = $this->applyTaxonomy->execute(
                $fresh,
                $taxonomy,
                allowPublished: $fresh->status !== ProductStatus::Draft,
            );

            if (! $applied) {
                throw ValidationException::withMessages([
                    'taxonomy' => ['Taxonomy could not be applied because this gift is archived.'],
                ]);
            }

            $fresh->taxonomy_classification_status = TaxonomyClassificationStatus::HumanApproved;
            $fresh->taxonomy_approved_at = now();
            $fresh->taxonomy_approved_by_user_id = $user->id;
            $fresh->taxonomy_proposal_pending = false;
            $fresh->save();

            return $fresh->fresh() ?? $fresh;
        });
    }

    private function canApprove(Product $product): bool
    {
        $status = $product->taxonomy_classification_status ?? TaxonomyClassificationStatus::None;

        if ($status === TaxonomyClassificationStatus::Review) {
            return is_array($product->taxonomy_classification_proposal)
                && $product->taxonomy_classification_proposal !== [];
        }

        return $status->isHumanLocked()
            && $product->taxonomyProposalIsPending()
            && is_array($product->taxonomy_classification_proposal)
            && $product->taxonomy_classification_proposal !== [];
    }

    /**
     * @param  list<TaxonomySemanticConflict>  $conflicts
     * @return list<string>
     */
    private function conflictMessages(array $conflicts): array
    {
        $messages = [];

        foreach ($conflicts as $conflict) {
            $messages[] = sprintf(
                '%s “%s” cannot be combined with %s “%s”. Correct the classification instead of approving this proposal.',
                $conflict->leftDimension->getLabel(),
                $conflict->leftDimension->valueLabel($conflict->leftId),
                $conflict->rightDimension->getLabel(),
                $conflict->rightDimension->valueLabel($conflict->rightId),
            );
        }

        return $messages !== [] ? $messages : ['The stored proposal has semantic taxonomy conflicts.'];
    }
}
