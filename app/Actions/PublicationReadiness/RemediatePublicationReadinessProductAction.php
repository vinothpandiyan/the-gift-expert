<?php

namespace App\Actions\PublicationReadiness;

use App\Actions\CatalogCuration\CaptureProductTaxonomySnapshotAction;
use App\Actions\CuratedCatalog\ApplyHumanProductTaxonomyClassificationAction;
use App\Actions\CuratedCatalog\ApproveCuratedTaxonomyProposalAction;
use App\Actions\CuratedCatalog\ClassifyCuratedMerchantProductAction;
use App\Actions\Product\AssessProductPublicationRequirementsAction;
use App\Enums\ProductStatus;
use App\Enums\PublicationReadinessRemediation;
use App\Models\Product;
use App\Models\User;
use App\PublicationReadiness\PublicationReadinessDiagnosis;
use App\PublicationReadiness\PublicationReadinessRemediationResult;
use Illuminate\Validation\ValidationException;

class RemediatePublicationReadinessProductAction
{
    public function __construct(
        private DiagnosePublicationReadinessProductAction $diagnose,
        private ApproveCuratedTaxonomyProposalAction $approveProposal,
        private ApplyHumanProductTaxonomyClassificationAction $humanClassify,
        private ClassifyCuratedMerchantProductAction $classify,
        private AssessProductPublicationRequirementsAction $assessPublication,
        private CaptureProductTaxonomySnapshotAction $captureSnapshot,
    ) {}

    /**
     * @param  array<string, mixed>|null  $humanTaxonomy
     */
    public function execute(
        Product $product,
        User $user,
        ?array $humanTaxonomy = null,
        bool $apply = true,
    ): PublicationReadinessRemediationResult {
        $product = $product->fresh() ?? $product;
        $statusBefore = $product->status;
        $diagnosis = $this->diagnose->execute($product);

        if (! $diagnosis instanceof PublicationReadinessDiagnosis) {
            throw ValidationException::withMessages([
                'product' => ['This gift is already publication-ready.'],
            ]);
        }

        if (! $apply || $diagnosis->remediation === PublicationReadinessRemediation::LeaveBlocked) {
            return $this->result($diagnosis, $product, $statusBefore);
        }

        match ($diagnosis->remediation) {
            PublicationReadinessRemediation::ApproveStoredProposal => $this->approveProposal->execute($product, $user),
            PublicationReadinessRemediation::HumanClassify => $this->humanClassify->execute(
                $product,
                $humanTaxonomy ?? $diagnosis->suggestedTaxonomy ?? [],
                $user,
            ),
            PublicationReadinessRemediation::RetryClassification => $this->classify->execute(
                $product,
                force: false,
                retryFailed: true,
            ),
            PublicationReadinessRemediation::LeaveBlocked => null,
        };

        $fresh = $product->fresh() ?? $product;

        if ($fresh->status !== $statusBefore) {
            throw ValidationException::withMessages([
                'product' => ['Readiness remediation must not publish or archive the gift.'],
            ]);
        }

        return $this->result($diagnosis, $fresh, $statusBefore);
    }

    private function result(
        PublicationReadinessDiagnosis $diagnosis,
        Product $product,
        ProductStatus $statusBefore,
    ): PublicationReadinessRemediationResult {
        $snapshot = $this->captureSnapshot->execute($product);
        $assessment = $this->assessPublication->execute($product);

        return new PublicationReadinessRemediationResult(
            diagnosis: $diagnosis,
            before: $diagnosis->before,
            after: [
                'classification_status' => $product->taxonomy_classification_status?->value,
                'primary_category_id' => $snapshot->primaryCategoryId,
                'category_ids' => $snapshot->categoryIds,
                'relationship_ids' => $snapshot->relationshipIds,
                'occasion_ids' => $snapshot->occasionIds,
                'interest_ids' => $snapshot->interestIds,
                'gift_type_ids' => $snapshot->giftTypeIds,
                'recipient_type_ids' => $snapshot->recipientTypeIds,
                'profession_ids' => $snapshot->professionIds,
            ],
            publishReady: $assessment['error_codes'] === [],
            blockersAfter: $assessment['error_codes'],
            published: $product->status === ProductStatus::Published && $statusBefore !== ProductStatus::Published,
            archived: $product->status === ProductStatus::Archived,
        );
    }
}
