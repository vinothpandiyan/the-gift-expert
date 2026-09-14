<?php

namespace App\Actions\CatalogCuration;

use App\Actions\CatalogCandidate\ValidateProductTaxonomyClassificationAction;
use App\Actions\Product\NormalizeProductCategoryAssignmentsAction;
use App\Actions\Product\ValidateProductTaxonomySemanticConflictsAction;
use App\CatalogCuration\HumanTaxonomyProposal;
use App\CatalogCuration\ProductTaxonomySnapshot;
use App\CommercialSourcing\ValidatedProductTaxonomyClassification;
use App\CuratedCatalog\TaxonomySemanticConflict;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ValidateHumanTaxonomyProposalAction
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const MODELS = [
        'relationships' => Relationship::class,
        'occasions' => Occasion::class,
        'interests' => Interest::class,
        'gift_types' => GiftType::class,
        'recipient_types' => RecipientType::class,
        'professions' => Profession::class,
    ];

    public function __construct(
        private ValidateProductTaxonomyClassificationAction $validateTaxonomy,
        private NormalizeProductCategoryAssignmentsAction $normalizeCategories,
        private ValidateProductTaxonomySemanticConflictsAction $semanticConflicts,
    ) {}

    public function execute(HumanTaxonomyProposal $proposal, ProductTaxonomySnapshot $snapshot): ValidatedProductTaxonomyClassification
    {
        if (! $proposal->hasChange()) {
            throw ValidationException::withMessages([
                'taxonomy_proposal' => ['Reclassify requires at least one explicit taxonomy change.'],
            ]);
        }

        foreach (ProductTaxonomySnapshot::dimensionKeys() as $dimension) {
            $add = $proposal->add[$dimension] ?? [];
            $remove = $proposal->remove[$dimension] ?? [];
            $overlap = array_values(array_intersect($add, $remove));

            if ($overlap !== []) {
                throw ValidationException::withMessages([
                    'taxonomy_'.$dimension.'_add' => ['The same '.$dimension.' value cannot be both added and removed.'],
                ]);
            }

            foreach ($remove as $id) {
                if (! in_array($id, $snapshot->idsFor($dimension), true)) {
                    throw ValidationException::withMessages([
                        'taxonomy_'.$dimension.'_remove' => ['A value selected for removal is not currently assigned.'],
                    ]);
                }
            }

            foreach ($add as $id) {
                if (in_array($id, $snapshot->idsFor($dimension), true)) {
                    throw ValidationException::withMessages([
                        'taxonomy_'.$dimension.'_add' => ['A value selected for addition is already assigned.'],
                    ]);
                }

                $this->assertActiveTaxonomy($dimension, $id);
            }
        }

        $resulting = $proposal->applyTo($snapshot);
        $primary = $resulting->primaryCategoryId;

        if ($primary === null) {
            throw ValidationException::withMessages([
                'taxonomy_primary_category_id' => ['A valid primary merchandising category is required.'],
            ]);
        }

        try {
            $normalized = $this->normalizeCategories->execute($primary);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'taxonomy_primary_category_id' => ['The primary category is not an active merchandising category.'],
            ]);
        }

        $validated = $this->validateTaxonomy->execute([
            'primary_category_id' => $normalized->primaryCategoryId,
            'category_ids' => $normalized->categoryIds,
            'relationship_ids' => $resulting->relationshipIds,
            'occasion_ids' => $resulting->occasionIds,
            'interest_ids' => $resulting->interestIds,
            'gift_type_ids' => $resulting->giftTypeIds,
            'recipient_type_ids' => $resulting->recipientTypeIds,
            'profession_ids' => $resulting->professionIds,
        ], [
            'categories' => 20,
            'occasions' => 50,
            'relationships' => 50,
            'recipient_types' => 50,
            'interests' => 50,
            'professions' => 50,
            'gift_types' => 50,
        ]);

        if ($validated->primaryCategoryId === null) {
            throw ValidationException::withMessages([
                'taxonomy_primary_category_id' => ['A valid primary merchandising category is required.'],
            ]);
        }

        if ($validated->rejectedIds !== []) {
            throw ValidationException::withMessages([
                'taxonomy_proposal' => ['Remove inactive or invalid taxonomy values before saving.'],
            ]);
        }

        $taxonomy = $validated->with(
            primaryCategoryId: $normalized->primaryCategoryId,
            categoryIds: $normalized->categoryIds,
        );
        $conflicts = $this->semanticConflicts->execute($taxonomy);

        if ($conflicts !== []) {
            throw ValidationException::withMessages([
                'taxonomy_proposal' => $this->conflictMessages($conflicts),
            ]);
        }

        return $taxonomy;
    }

    private function assertActiveTaxonomy(string $dimension, int $id): void
    {
        $model = self::MODELS[$dimension];
        $record = $model::query()->whereKey($id)->first();

        if (! $record instanceof Model || $record->is_active !== true) {
            throw ValidationException::withMessages([
                'taxonomy_'.$dimension.'_add' => ['A proposed taxonomy value is inactive or does not exist.'],
            ]);
        }
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
