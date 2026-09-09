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

class ApplyHumanProductTaxonomyClassificationAction
{
    /**
     * @var array<string, int>
     */
    private const HUMAN_CAPS = [
        'categories' => 20,
        'occasions' => 50,
        'relationships' => 50,
        'recipient_types' => 50,
        'interests' => 50,
        'professions' => 50,
        'gift_types' => 50,
    ];

    public function __construct(
        private ValidateProductTaxonomyClassificationAction $validateTaxonomy,
        private NormalizeProductCategoryAssignmentsAction $normalizeCategories,
        private ValidateProductTaxonomySemanticConflictsAction $semanticConflicts,
        private ApplyProductTaxonomyClassificationAction $applyTaxonomy,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(Product $product, array $input, User $user): Product
    {
        $product = $product->fresh() ?? $product;

        $validated = $this->validateTaxonomy->execute($input, self::HUMAN_CAPS);

        if ($validated->primaryCategoryId === null) {
            throw ValidationException::withMessages([
                'primary_category_id' => ['Select one active merchandising category as the primary category.'],
            ]);
        }

        if ($validated->rejectedIds !== []) {
            throw ValidationException::withMessages([
                'taxonomy' => ['Remove inactive or invalid taxonomy values before saving.'],
            ]);
        }

        try {
            $normalized = $this->normalizeCategories->execute($validated->primaryCategoryId);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'primary_category_id' => ['The primary category is not an active merchandising category.'],
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

            $applied = $this->applyTaxonomy->execute(
                $fresh,
                $taxonomy,
                allowPublished: $fresh->status !== ProductStatus::Draft,
            );

            if (! $applied) {
                throw ValidationException::withMessages([
                    'taxonomy' => ['Taxonomy cannot be changed on an archived gift.'],
                ]);
            }

            $fresh->taxonomy_classification_status = TaxonomyClassificationStatus::HumanOverridden;
            $fresh->taxonomy_approved_at = now();
            $fresh->taxonomy_approved_by_user_id = $user->id;
            $fresh->taxonomy_proposal_pending = false;
            $fresh->save();

            return $fresh->fresh() ?? $fresh;
        });
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
                '%s “%s” cannot be combined with %s “%s”. Correct the taxonomy before saving.',
                $conflict->leftDimension->getLabel(),
                $conflict->leftDimension->valueLabel($conflict->leftId),
                $conflict->rightDimension->getLabel(),
                $conflict->rightDimension->valueLabel($conflict->rightId),
            );
        }

        return $messages !== [] ? $messages : ['This taxonomy combination is not allowed.'];
    }
}
