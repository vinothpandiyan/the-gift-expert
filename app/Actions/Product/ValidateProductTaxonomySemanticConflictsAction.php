<?php

namespace App\Actions\Product;

use App\CommercialSourcing\ValidatedProductTaxonomyClassification;
use App\CuratedCatalog\TaxonomySemanticConflict;
use App\Enums\TaxonomyDimension;
use App\Taxonomy\TaxonomyApplicabilityIndex;

class ValidateProductTaxonomySemanticConflictsAction
{
    /**
     * @return list<TaxonomySemanticConflict>
     */
    public function execute(ValidatedProductTaxonomyClassification $taxonomy): array
    {
        $assignments = $this->assignments($taxonomy);

        if (count($assignments) < 2) {
            return [];
        }

        $index = TaxonomyApplicabilityIndex::load();
        $conflicts = [];
        $seen = [];

        foreach ($assignments as $i => $left) {
            foreach ($assignments as $j => $right) {
                if ($j <= $i) {
                    continue;
                }

                if ($left['dimension'] === $right['dimension']) {
                    continue;
                }

                if ($index->pairIsApplicable($left['dimension'], $left['id'], $right['dimension'], $right['id'])) {
                    continue;
                }

                $key = TaxonomyApplicabilityIndex::class.':'.$left['dimension']->value.':'.$left['id'].':'.$right['dimension']->value.':'.$right['id'];

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $conflicts[] = new TaxonomySemanticConflict(
                    leftDimension: $left['dimension'],
                    leftId: $left['id'],
                    rightDimension: $right['dimension'],
                    rightId: $right['id'],
                );
            }
        }

        return $conflicts;
    }

    /**
     * @return list<array{dimension: TaxonomyDimension, id: int}>
     */
    private function assignments(ValidatedProductTaxonomyClassification $taxonomy): array
    {
        $rows = [];
        $map = [
            TaxonomyDimension::Occasion->value => $taxonomy->occasionIds,
            TaxonomyDimension::Relationship->value => $taxonomy->relationshipIds,
            TaxonomyDimension::RecipientType->value => $taxonomy->recipientTypeIds,
            TaxonomyDimension::Interest->value => $taxonomy->interestIds,
            TaxonomyDimension::Profession->value => $taxonomy->professionIds,
            TaxonomyDimension::GiftType->value => $taxonomy->giftTypeIds,
        ];

        foreach ($map as $dimensionValue => $ids) {
            $dimension = TaxonomyDimension::from($dimensionValue);

            foreach ($ids as $id) {
                $rows[] = [
                    'dimension' => $dimension,
                    'id' => (int) $id,
                ];
            }
        }

        return $rows;
    }
}
