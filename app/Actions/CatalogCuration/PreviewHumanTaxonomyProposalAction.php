<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\HumanTaxonomyProposal;
use App\CatalogCuration\HumanTaxonomyProposalPreview;
use App\CatalogCuration\ProductTaxonomySnapshot;
use Illuminate\Support\Str;

class PreviewHumanTaxonomyProposalAction
{
    /**
     * @param  array<string, array<int, string>>  $optionNames
     */
    public function execute(
        HumanTaxonomyProposal $proposal,
        ProductTaxonomySnapshot $snapshot,
        array $optionNames = [],
    ): HumanTaxonomyProposalPreview {
        $rows = [];
        $hasChanges = false;

        $categoryName = function (?int $id) use ($snapshot, $optionNames): string {
            if ($id === null) {
                return 'none';
            }

            return $optionNames['categories'][$id]
                ?? $snapshot->name('categories', $id);
        };

        if ($proposal->categoryTo !== null && $proposal->categoryTo !== $snapshot->primaryCategoryId) {
            $rows[] = [
                'dimension' => 'Category',
                'changes' => [
                    '- '.$categoryName($snapshot->primaryCategoryId),
                    '+ '.$categoryName($proposal->categoryTo),
                ],
            ];
            $hasChanges = true;
        } else {
            $rows[] = [
                'dimension' => 'Category',
                'changes' => ['unchanged'],
            ];
        }

        foreach (ProductTaxonomySnapshot::dimensionKeys() as $dimension) {
            $changes = [];

            foreach ($proposal->remove[$dimension] ?? [] as $id) {
                $changes[] = '- '.$this->label($dimension, $id, $snapshot, $optionNames);
            }

            foreach ($proposal->add[$dimension] ?? [] as $id) {
                $changes[] = '+ '.$this->label($dimension, $id, $snapshot, $optionNames);
            }

            if ($changes !== []) {
                $hasChanges = true;
            }

            $rows[] = [
                'dimension' => Str::of($dimension)->replace('_', ' ')->headline()->toString(),
                'changes' => $changes !== [] ? $changes : ['unchanged'],
            ];
        }

        return new HumanTaxonomyProposalPreview($rows, $hasChanges);
    }

    /**
     * @param  array<string, array<int, string>>  $optionNames
     */
    private function label(
        string $dimension,
        int $id,
        ProductTaxonomySnapshot $snapshot,
        array $optionNames,
    ): string {
        return $optionNames[$dimension][$id] ?? $snapshot->name($dimension, $id);
    }
}
