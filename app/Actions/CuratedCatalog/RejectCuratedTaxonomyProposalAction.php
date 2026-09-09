<?php

namespace App\Actions\CuratedCatalog;

use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyClassificationWarningCode;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectCuratedTaxonomyProposalAction
{
    public function execute(Product $product): Product
    {
        $product = $product->fresh() ?? $product;
        $status = $product->taxonomy_classification_status ?? TaxonomyClassificationStatus::None;

        if ($status->isHumanLocked()) {
            if (! $product->taxonomyProposalIsPending()) {
                throw ValidationException::withMessages([
                    'taxonomy' => ['There is no pending AI proposal to reject.'],
                ]);
            }

            return $this->rejectPendingHumanProposal($product);
        }

        if (! in_array($status, [
            TaxonomyClassificationStatus::Review,
            TaxonomyClassificationStatus::AiProposed,
        ], true)) {
            throw ValidationException::withMessages([
                'taxonomy' => ['There is no AI proposal to reject.'],
            ]);
        }

        return DB::transaction(function () use ($product): Product {
            $fresh = Product::query()->lockForUpdate()->findOrFail($product->id);
            $reason = TaxonomyClassificationWarningCode::HumanRejectedProposal->value;
            $proposal = is_array($fresh->taxonomy_classification_proposal)
                ? $fresh->taxonomy_classification_proposal
                : [];
            $proposal['rejected'] = true;
            $proposal['review_reasons'] = array_values(array_unique(array_merge(
                is_array($proposal['review_reasons'] ?? null) ? $proposal['review_reasons'] : [],
                [$reason],
            )));

            $fresh->taxonomy_classification_status = TaxonomyClassificationStatus::Failed;
            $fresh->taxonomy_review_reasons = [$reason];
            $fresh->taxonomy_classification_warnings = array_values(array_unique(array_merge(
                is_array($fresh->taxonomy_classification_warnings) ? $fresh->taxonomy_classification_warnings : [],
                [$reason],
            )));
            $fresh->taxonomy_classification_proposal = $proposal !== [] ? $proposal : [
                'rejected' => true,
                'review_reasons' => [$reason],
            ];
            $fresh->taxonomy_proposal_pending = false;
            $fresh->save();

            return $fresh->fresh() ?? $fresh;
        });
    }

    private function rejectPendingHumanProposal(Product $product): Product
    {
        return DB::transaction(function () use ($product): Product {
            $fresh = Product::query()->lockForUpdate()->findOrFail($product->id);
            $reason = TaxonomyClassificationWarningCode::HumanRejectedProposal->value;
            $proposal = is_array($fresh->taxonomy_classification_proposal)
                ? $fresh->taxonomy_classification_proposal
                : [];
            $proposal['rejected'] = true;

            $fresh->taxonomy_proposal_pending = false;
            $fresh->taxonomy_classification_proposal = $proposal;
            $fresh->taxonomy_review_reasons = array_values(array_unique(array_merge(
                is_array($fresh->taxonomy_review_reasons) ? $fresh->taxonomy_review_reasons : [],
                [$reason],
            )));
            $fresh->save();

            return $fresh->fresh() ?? $fresh;
        });
    }
}
