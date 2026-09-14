<?php

namespace App\Actions\CatalogCuration;

use App\Actions\Product\ApplyProductTaxonomyClassificationAction;
use App\CatalogCuration\HumanCurationReclassificationManifest;
use App\CatalogCuration\HumanTaxonomyProposal;
use App\CatalogCuration\ProductTaxonomySnapshot;
use App\Enums\ProductCurationDecision as ProductCurationDecisionValue;
use App\Enums\ProductCurationRemediationStatus;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ApplyHumanCurationReclassificationAction
{
    public function __construct(
        private CaptureProductTaxonomySnapshotAction $captureSnapshot,
        private ValidateHumanTaxonomyProposalAction $validateProposal,
        private ApplyProductTaxonomyClassificationAction $applyTaxonomy,
    ) {}

    public function execute(ProductCurationDecision $decision, User $user): ProductCurationDecision
    {
        $record = DB::transaction(function () use ($decision, $user): ProductCurationDecision {
            $lockedDecision = ProductCurationDecision::query()
                ->whereKey($decision->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedDecision instanceof ProductCurationDecision) {
                throw ValidationException::withMessages([
                    'decision' => ['The human curation decision could not be locked for remediations.'],
                ]);
            }

            $this->assertPreconditions($lockedDecision);

            $product = Product::query()->whereKey($lockedDecision->product_id)->lockForUpdate()->first();

            if (! $product instanceof Product) {
                throw ValidationException::withMessages([
                    'product' => ['The gift could not be locked for taxonomy remediations.'],
                ]);
            }

            if ($product->status === ProductStatus::Archived) {
                throw ValidationException::withMessages([
                    'product' => ['Archived gifts are not reclassified during P2 taxonomy remediations.'],
                ]);
            }

            $proposal = HumanTaxonomyProposal::fromArray(
                is_array($lockedDecision->taxonomy_proposal) ? $lockedDecision->taxonomy_proposal : [],
            );
            $before = $this->captureSnapshot->execute($product);
            $lifecycleBefore = $product->taxonomy_classification_status?->value;
            $statusBefore = $product->status;
            $publishedAtBefore = $product->published_at;
            $taxonomy = $this->validateProposal->execute($proposal, $before);

            $applied = $this->applyTaxonomy->execute(
                $product,
                $taxonomy,
                allowPublished: $product->status !== ProductStatus::Draft,
            );

            if (! $applied) {
                throw ValidationException::withMessages([
                    'taxonomy' => ['Taxonomy remediations could not be applied to this gift.'],
                ]);
            }

            $product->taxonomy_classification_status = TaxonomyClassificationStatus::HumanOverridden;
            $product->taxonomy_approved_at = now();
            $product->taxonomy_approved_by_user_id = $user->id;
            $product->taxonomy_proposal_pending = false;
            $product->save();

            $fresh = $product->fresh() ?? $product;

            if ($fresh->status !== $statusBefore || $this->publishedAtChanged($publishedAtBefore, $fresh->published_at)) {
                throw ValidationException::withMessages([
                    'product' => ['Taxonomy remediations must not change publication or lifecycle state.'],
                ]);
            }

            $after = $this->captureSnapshot->execute($fresh);
            $manifest = $this->manifest(
                $lockedDecision,
                $proposal,
                $before,
                $after,
                $lifecycleBefore,
                $fresh->taxonomy_classification_status?->value,
                $user,
            );

            $lockedDecision->remediation_status = ProductCurationRemediationStatus::Completed;
            $lockedDecision->remediation_manifest = $manifest->toArray();
            $lockedDecision->remediated_at = now();
            $lockedDecision->remediated_by_user_id = $user->id;
            $lockedDecision->save();

            Log::info('catalog_curation.product_reclassified', [
                'decision_id' => $lockedDecision->id,
                'product_id' => $fresh->id,
                'source_audit_run_id' => $lockedDecision->source_audit_run_id,
                'remediated_by_user_id' => $user->id,
                'manifest' => $manifest->toArray(),
            ]);

            return $lockedDecision->fresh() ?? $lockedDecision;
        });

        app(QueryHumanCurationQueueAction::class)->flush();

        return $record;
    }

    private function assertPreconditions(ProductCurationDecision $decision): void
    {
        if ($decision->decision !== ProductCurationDecisionValue::Reclassify) {
            throw ValidationException::withMessages([
                'decision' => ['Only an explicit RECLASSIFY human decision can apply taxonomy remediations.'],
            ]);
        }

        if (! $decision->isCurrent()) {
            throw ValidationException::withMessages([
                'decision' => ['Only the current human curation decision can apply taxonomy remediations.'],
            ]);
        }

        if ($decision->remediation_status !== ProductCurationRemediationStatus::Pending) {
            throw ValidationException::withMessages([
                'remediation_status' => ['Taxonomy remediations can run only while the decision is pending.'],
            ]);
        }

        $audit = ProductCurationAudit::query()->find($decision->source_product_curation_audit_id);

        if (! $audit instanceof ProductCurationAudit
            || (int) $audit->product_id !== (int) $decision->product_id
            || (string) $audit->run_id !== (string) $decision->source_audit_run_id) {
            throw ValidationException::withMessages([
                'source_audit' => ['The source audit for this decision is no longer valid.'],
            ]);
        }
    }

    private function manifest(
        ProductCurationDecision $decision,
        HumanTaxonomyProposal $proposal,
        ProductTaxonomySnapshot $before,
        ProductTaxonomySnapshot $after,
        ?string $lifecycleBefore,
        ?string $lifecycleAfter,
        User $user,
    ): HumanCurationReclassificationManifest {
        $category = null;

        if ($proposal->categoryTo !== null && $proposal->categoryTo !== $before->primaryCategoryId) {
            $category = [
                'from' => $before->primaryCategoryId === null
                    ? null
                    : ['id' => $before->primaryCategoryId, 'name' => $before->name('categories', $before->primaryCategoryId)],
                'to' => [
                    'id' => $after->primaryCategoryId ?? $proposal->categoryTo,
                    'name' => $after->name('categories', $after->primaryCategoryId ?? $proposal->categoryTo),
                ],
            ];
        }

        $added = [];
        $removed = [];

        foreach (ProductTaxonomySnapshot::dimensionKeys() as $dimension) {
            $added[$dimension] = array_map(
                fn (int $id): array => ['id' => $id, 'name' => $after->name($dimension, $id) !== '#'.$id
                    ? $after->name($dimension, $id)
                    : $before->name($dimension, $id)],
                $proposal->add[$dimension] ?? [],
            );
            $removed[$dimension] = array_map(
                fn (int $id): array => ['id' => $id, 'name' => $before->name($dimension, $id)],
                $proposal->remove[$dimension] ?? [],
            );
        }

        return new HumanCurationReclassificationManifest(
            productId: (int) $decision->product_id,
            decisionId: (int) $decision->id,
            category: $category,
            added: $added,
            removed: $removed,
            classificationBefore: $lifecycleBefore,
            classificationAfter: $lifecycleAfter,
            actorId: (int) $user->id,
            timestamp: now()->toDateTimeString(),
            reason: $decision->reason_notes,
        );
    }

    private function publishedAtChanged(mixed $before, mixed $after): bool
    {
        if ($before === null && $after === null) {
            return false;
        }

        if ($before === null || $after === null) {
            return true;
        }

        return ! $before->equalTo($after);
    }
}
