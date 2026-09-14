<?php

namespace App\Actions\CatalogCuration;

use App\CatalogCuration\HumanTaxonomyProposal;
use App\Enums\CatalogRole;
use App\Enums\ProductCurationDecision as ProductCurationDecisionValue;
use App\Enums\ProductCurationDecisionReasonCode;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RecordProductCurationDecisionAction
{
    public function __construct(
        private ResolveCurationRemediationStatusAction $resolveRemediation,
        private ValidateProductCurationDecisionAction $validate,
        private ApplyProductCurationRemediationAction $applyRemediation,
        private CaptureProductTaxonomySnapshotAction $captureSnapshot,
        private ValidateHumanTaxonomyProposalAction $validateProposal,
    ) {}

    /**
     * @param  list<ProductCurationDecisionReasonCode|string>  $reasonCodes
     */
    public function execute(
        Product $product,
        ProductCurationAudit $audit,
        ProductCurationDecisionValue $decision,
        array $reasonCodes,
        ?string $reasonNotes,
        User $user,
        ?CatalogRole $catalogRole = null,
        ?int $expectedCurrentDecisionId = null,
        bool $confirmSupersede = false,
        ?HumanTaxonomyProposal $taxonomyProposal = null,
    ): ProductCurationDecision {
        $record = DB::transaction(function () use (
            $product,
            $audit,
            $decision,
            $reasonCodes,
            $reasonNotes,
            $user,
            $catalogRole,
            $expectedCurrentDecisionId,
            $confirmSupersede,
            $taxonomyProposal,
        ): ProductCurationDecision {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->first();

            if (! $locked instanceof Product) {
                throw ValidationException::withMessages([
                    'product' => ['The gift could not be locked for review.'],
                ]);
            }

            $current = ProductCurationDecision::query()
                ->current()
                ->where('product_id', $locked->id)
                ->lockForUpdate()
                ->first();

            $this->validate->execute(
                $locked,
                $audit,
                $decision,
                $reasonCodes,
                $reasonNotes,
                $catalogRole,
                $expectedCurrentDecisionId,
                $confirmSupersede,
                $current,
                $taxonomyProposal,
            );

            if ($decision === ProductCurationDecisionValue::Reclassify && $taxonomyProposal instanceof HumanTaxonomyProposal) {
                $this->validateProposal->execute(
                    $taxonomyProposal,
                    $this->captureSnapshot->execute($locked),
                );
            }

            if ($current instanceof ProductCurationDecision) {
                $current->current_for_product_id = null;
                $current->save();
            }

            $codes = array_map(
                fn (ProductCurationDecisionReasonCode|string $code): string => $code instanceof ProductCurationDecisionReasonCode
                    ? $code->value
                    : $code,
                $reasonCodes,
            );

            $record = ProductCurationDecision::query()->create([
                'product_id' => $locked->id,
                'source_audit_run_id' => $audit->run_id,
                'source_product_curation_audit_id' => $audit->id,
                'decision' => $decision,
                'reason_codes' => array_values($codes),
                'reason_notes' => filled($reasonNotes) ? trim($reasonNotes) : null,
                'catalog_role' => $decision->allowsCatalogRole() ? $catalogRole : null,
                'taxonomy_proposal' => $decision === ProductCurationDecisionValue::Reclassify
                    ? $taxonomyProposal?->toArray()
                    : null,
                'remediation_status' => $this->resolveRemediation->execute($decision),
                'previous_decision_id' => $current?->id,
                'current_for_product_id' => $locked->id,
                'decided_by_user_id' => $user->id,
                'decided_at' => now(),
            ]);

            Log::info($current instanceof ProductCurationDecision
                ? 'catalog_curation.decision_superseded'
                : 'catalog_curation.decision_recorded', [
                    'decision_id' => $record->id,
                    'product_id' => $locked->id,
                    'decision' => $decision->value,
                    'previous_decision_id' => $current?->id,
                    'source_audit_run_id' => $audit->run_id,
                    'source_product_curation_audit_id' => $audit->id,
                    'decided_by_user_id' => $user->id,
                ]);

            if ($decision === ProductCurationDecisionValue::Deactivate) {
                $record = $this->applyRemediation->execute($record);
            }

            return $record->fresh() ?? $record;
        });

        app(QueryHumanCurationQueueAction::class)->flush();

        return $record;
    }
}
