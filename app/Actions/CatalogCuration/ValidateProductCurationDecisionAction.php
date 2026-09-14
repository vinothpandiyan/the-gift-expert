<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\HumanTaxonomyProposal;
use App\Enums\CatalogRole;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationDecisionReasonCode;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;
use Illuminate\Validation\ValidationException;

class ValidateProductCurationDecisionAction
{
    /**
     * @param  list<ProductCurationDecisionReasonCode|string>  $reasonCodes
     */
    public function execute(
        Product $product,
        ProductCurationAudit $audit,
        ProductCurationDecision $decision,
        array $reasonCodes,
        ?string $reasonNotes,
        ?CatalogRole $catalogRole,
        ?int $expectedCurrentDecisionId,
        bool $confirmSupersede,
        ?ProductCurationDecisionRecord $current,
        ?HumanTaxonomyProposal $taxonomyProposal = null,
    ): void {
        if ((int) $audit->product_id !== (int) $product->id) {
            throw ValidationException::withMessages([
                'source_audit' => ['The source audit does not belong to this gift.'],
            ]);
        }

        $codes = ProductCurationDecisionReasonCode::fromValues(
            array_map(
                fn (ProductCurationDecisionReasonCode|string $code): string => $code instanceof ProductCurationDecisionReasonCode
                    ? $code->value
                    : $code,
                $reasonCodes,
            ),
        );
        $notes = trim((string) $reasonNotes);
        $hasNotes = $notes !== '';
        $hasCodes = $codes !== [];

        if ($decision === ProductCurationDecision::Defer && ! $hasNotes && ! $hasCodes) {
            throw ValidationException::withMessages([
                'reason_notes' => ['Defer requires an explanation.'],
            ]);
        }

        if ($decision === ProductCurationDecision::Defer && ! $hasNotes) {
            throw ValidationException::withMessages([
                'reason_notes' => ['Defer requires notes explaining why a decision cannot be made yet.'],
            ]);
        }

        if ($decision->isKeepFamily() && ! collect($codes)->contains(
            fn (ProductCurationDecisionReasonCode $code): bool => $code->isRetention(),
        )) {
            throw ValidationException::withMessages([
                'reason_codes' => ['Keep, Feature, and Keep niche require at least one structured retention reason.'],
            ]);
        }

        if (in_array($decision, [
            ProductCurationDecision::Deactivate,
            ProductCurationDecision::RemoveCandidate,
        ], true) && ! collect($codes)->contains(
            fn (ProductCurationDecisionReasonCode $code): bool => $code->isRemoval(),
        )) {
            throw ValidationException::withMessages([
                'reason_codes' => ['Removal or deactivation requires at least one negative structured reason.'],
            ]);
        }

        if ($decision === ProductCurationDecision::Deactivate && ! $hasNotes) {
            throw ValidationException::withMessages([
                'reason_notes' => ['Deactivation requires notes explaining why the gift should leave the catalog.'],
            ]);
        }

        if ($decision === ProductCurationDecision::Reclassify) {
            $hasTaxonomyReason = collect($codes)->contains(
                fn (ProductCurationDecisionReasonCode $code): bool => $code->isTaxonomy(),
            );

            if (! $hasTaxonomyReason && ! $hasNotes) {
                throw ValidationException::withMessages([
                    'reason_codes' => ['Reclassify requires a taxonomy reason or notes describing the misclassification.'],
                ]);
            }

            if (! $taxonomyProposal instanceof HumanTaxonomyProposal || ! $taxonomyProposal->hasChange()) {
                throw ValidationException::withMessages([
                    'taxonomy_proposal' => ['Reclassify requires a structured taxonomy proposal with at least one change.'],
                ]);
            }
        }

        if ($catalogRole instanceof CatalogRole) {
            if (! $decision->allowsCatalogRole()) {
                throw ValidationException::withMessages([
                    'catalog_role' => ['A merchandising role can only be assigned when the gift is kept or featured.'],
                ]);
            }

            if ($catalogRole === CatalogRole::Undifferentiated) {
                throw ValidationException::withMessages([
                    'catalog_role' => ['Undifferentiated is not a human merchandising role.'],
                ]);
            }
        }

        if ($current instanceof ProductCurationDecisionRecord) {
            $expectedMatches = $expectedCurrentDecisionId !== null
                && (int) $expectedCurrentDecisionId === (int) $current->id;

            if (! $expectedMatches && ! $confirmSupersede) {
                throw ValidationException::withMessages([
                    'expected_current_decision_id' => [
                        'A newer human curation decision already exists. Review the current decision and confirm if you still want to supersede it.',
                    ],
                ]);
            }
        } elseif ($expectedCurrentDecisionId !== null) {
            throw ValidationException::withMessages([
                'expected_current_decision_id' => [
                    'The expected current decision no longer exists. Reload the review before recording a new decision.',
                ],
            ]);
        }
    }
}
