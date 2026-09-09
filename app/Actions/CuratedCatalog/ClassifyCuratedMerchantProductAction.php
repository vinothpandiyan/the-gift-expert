<?php

namespace App\Actions\CuratedCatalog;

use App\Actions\Product\ApplyProductTaxonomyClassificationAction;
use App\Actions\Product\NormalizeProductCategoryAssignmentsAction;
use App\CommercialSourcing\CommercialEnrichmentException;
use App\CuratedCatalog\ClassifyCuratedMerchantProductResult;
use App\CuratedCatalog\CuratedClassificationProposal;
use App\CuratedCatalog\CuratedProductEnrichmentResult;
use App\Enums\EditorialOwnership;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyClassificationWarningCode;
use App\Models\CuratedProductIntakeItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ClassifyCuratedMerchantProductAction
{
    public function __construct(
        private ShouldReclassifyCuratedMerchantProductAction $shouldReclassify,
        private BuildCuratedMerchantProductInputFromProductAction $buildInput,
        private ResolveCatalogSourceRelationshipHintsAction $resolveHints,
        private BuildCuratedTaxonomyContentFingerprintAction $contentFingerprint,
        private BuildCuratedRelationshipHintFingerprintAction $hintFingerprint,
        private EnrichCuratedMerchantProductAction $enrich,
        private NormalizeProductCategoryAssignmentsAction $normalizeCategories,
        private ResolveCuratedClassificationDecisionAction $decide,
        private ApplyProductTaxonomyClassificationAction $applyTaxonomy,
    ) {}

    public function execute(
        Product $product,
        bool $force = false,
        bool $retryFailed = false,
        bool $preserveHumanAppliedTaxonomy = false,
    ): ClassifyCuratedMerchantProductResult {
        $product = $product->fresh() ?? $product;
        $decision = $this->shouldReclassify->execute($product, $force, $retryFailed);

        if (! $decision->shouldReclassify) {
            return new ClassifyCuratedMerchantProductResult(
                product: $product,
                status: $product->taxonomy_classification_status ?? TaxonomyClassificationStatus::None,
                classified: false,
                reason: $decision->reason,
                warnings: [],
                reviewReasons: [],
                proposal: null,
            );
        }

        $contentHash = $this->contentFingerprint->execute($product);
        $hintHash = $this->hintFingerprint->execute($product);
        $hintIds = $this->resolveHints->execute($product);

        try {
            [$merchant, $input] = $this->buildInput->execute($product);
            $enrichment = $this->enrich->execute(
                $merchant,
                $input,
                $hintIds,
                is_string($product->short_description) ? $product->short_description : null,
                is_string($product->description) ? $product->description : null,
            );
        } catch (CommercialEnrichmentException $exception) {
            return $this->persistFailed(
                $product,
                $contentHash,
                $hintHash,
                $hintIds,
                $this->failedCode($exception),
                $exception->getMessage(),
                $preserveHumanAppliedTaxonomy,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->persistFailed(
                $product,
                $contentHash,
                $hintHash,
                $hintIds,
                TaxonomyClassificationWarningCode::EnrichmentFailed->value,
                $exception->getMessage(),
                $preserveHumanAppliedTaxonomy,
            );
        } catch (Throwable $exception) {
            return $this->persistFailed(
                $product,
                $contentHash,
                $hintHash,
                $hintIds,
                TaxonomyClassificationWarningCode::EnrichmentFailed->value,
                $exception->getMessage(),
                $preserveHumanAppliedTaxonomy,
            );
        }

        $warnings = $enrichment->warnings;

        if ($enrichment->taxonomy->rejectedIds !== []) {
            $warnings[] = TaxonomyClassificationWarningCode::InactiveTaxonomyIdRejected->value;
        }

        $taxonomy = $enrichment->taxonomy;

        if ($taxonomy->primaryCategoryId !== null) {
            try {
                $normalized = $this->normalizeCategories->execute($taxonomy->primaryCategoryId);
            } catch (InvalidArgumentException) {
                return $this->persistFailed(
                    $product,
                    $contentHash,
                    $hintHash,
                    $hintIds,
                    TaxonomyClassificationWarningCode::InvalidPrimaryCategory->value,
                    'The primary category is not an active merchandising category.',
                    $preserveHumanAppliedTaxonomy,
                    $this->structuredResponseSnapshot($enrichment, $warnings),
                );
            }

            $taxonomy = $taxonomy->with(
                primaryCategoryId: $normalized->primaryCategoryId,
                categoryIds: $normalized->categoryIds,
            );
        }

        $resolved = $this->decide->execute(
            $taxonomy,
            $enrichment->confidence,
            $enrichment->taxonomyGap,
            $hintIds,
            $warnings,
            $enrichment->name,
            $enrichment->shortDescription,
            $enrichment->description,
            $enrichment->brand,
            $enrichment->reasoningSummary,
        );

        $proposal = $resolved->proposal;
        $proposalArray = $proposal?->toArray() ?? [];
        $proposalArray['source_title'] = $this->contentFingerprint->sourceTitle($product);

        if ($resolved->status === TaxonomyClassificationStatus::Failed) {
            $proposalArray['structured_response'] = $this->structuredResponseSnapshot($enrichment, $resolved->warnings);
        }

        $product = DB::transaction(function () use (
            $product,
            $resolved,
            $proposal,
            $proposalArray,
            $contentHash,
            $hintHash,
            $preserveHumanAppliedTaxonomy,
        ): Product {
            $fresh = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($fresh->taxonomyClassificationIsHumanLocked()) {
                if (! $preserveHumanAppliedTaxonomy) {
                    return $fresh;
                }

                $this->storePendingProposal(
                    $fresh,
                    $proposalArray,
                    $contentHash,
                    $hintHash,
                    $resolved->reviewReasons,
                    $resolved->warnings,
                    $resolved->taxonomyGap->suggestedConcept,
                    $resolved->taxonomyGap->explanation,
                    $proposal?->reasoningSummary ?: null,
                );

                return $fresh->fresh() ?? $fresh;
            }

            $fresh->taxonomy_classification_status = $resolved->status;
            $fresh->taxonomy_classified_at = now();
            $fresh->taxonomy_content_fingerprint = $contentHash;
            $fresh->taxonomy_relationship_hint_fingerprint = $hintHash;
            $fresh->taxonomy_classification_version = (int) config('curated_catalog.taxonomy_classification.version', 1);
            $fresh->taxonomy_review_reasons = $resolved->reviewReasons !== [] ? $resolved->reviewReasons : null;
            $fresh->taxonomy_classification_warnings = $resolved->warnings !== [] ? $resolved->warnings : null;
            $fresh->taxonomy_gap_suggestion = $resolved->taxonomyGap->suggestedConcept;
            $fresh->taxonomy_gap_explanation = $resolved->taxonomyGap->explanation;
            $fresh->taxonomy_reasoning = $proposal?->reasoningSummary ?: null;
            $fresh->taxonomy_classification_proposal = $proposalArray !== [] ? $proposalArray : null;
            $fresh->taxonomy_proposal_pending = false;
            $fresh->status = ProductStatus::Draft;

            if ($resolved->status === TaxonomyClassificationStatus::AiAccepted && $proposal instanceof CuratedClassificationProposal) {
                $fresh->brand = $proposal->brand;

                if (! $fresh->editorialCopyIsHumanOwned()) {
                    if (is_string($proposal->name) && $proposal->name !== '') {
                        $fresh->name = $proposal->name;
                    }

                    $fresh->short_description = $proposal->shortDescription;
                    $fresh->description = $proposal->description;
                    $fresh->editorial_ownership = EditorialOwnership::Ai;
                    $fresh->editorial_generation_version = 0;
                    $fresh->editorial_reviewed_at = null;
                    $fresh->editorial_reviewed_by_user_id = null;
                }
            }

            $fresh->save();

            if ($resolved->status === TaxonomyClassificationStatus::AiAccepted && $proposal instanceof CuratedClassificationProposal) {
                $this->applyTaxonomy->execute($fresh, $proposal->taxonomy);
            }

            return $fresh->fresh() ?? $fresh;
        });

        $this->auditLatestIntakeItem($product, $resolved->warnings, $proposalArray);

        if ($resolved->status === TaxonomyClassificationStatus::Failed) {
            $this->logFailure(
                $product,
                $resolved->reviewReasons[0] ?? TaxonomyClassificationWarningCode::MissingPrimaryCategory->value,
                $resolved->taxonomyGap->explanation
                    ?? 'Curated enrichment did not produce a valid primary category.',
            );
        }

        return new ClassifyCuratedMerchantProductResult(
            product: $product,
            status: $product->taxonomy_classification_status ?? $resolved->status,
            classified: true,
            reason: $resolved->status->value,
            warnings: $resolved->warnings,
            reviewReasons: $resolved->reviewReasons,
            proposal: $proposal,
        );
    }

    /**
     * @param  list<int>  $hintIds
     * @param  array<string, mixed>|null  $structuredResponse
     */
    private function persistFailed(
        Product $product,
        string $contentHash,
        string $hintHash,
        array $hintIds,
        string $code,
        string $message,
        bool $preserveHumanAppliedTaxonomy = false,
        ?array $structuredResponse = null,
    ): ClassifyCuratedMerchantProductResult {
        $proposal = [
            'source_relationship_hint_ids' => $hintIds,
            'warnings' => [$code],
            'review_reasons' => [$code],
            'classification_version' => (int) config('curated_catalog.taxonomy_classification.version', 1),
            'source_title' => $this->contentFingerprint->sourceTitle($product),
        ];

        if (is_array($structuredResponse)) {
            $proposal = array_merge($proposal, $this->proposalFieldsFromStructuredResponse($structuredResponse));
            $proposal['structured_response'] = $structuredResponse;
        }

        $gapSuggestion = is_string($structuredResponse['taxonomy_gap']['suggested_concept'] ?? null)
            ? $structuredResponse['taxonomy_gap']['suggested_concept']
            : null;
        $gapExplanation = is_string($structuredResponse['taxonomy_gap']['explanation'] ?? null)
            ? $structuredResponse['taxonomy_gap']['explanation']
            : $message;
        $reasoning = is_array($structuredResponse['reasoning_summary'] ?? null)
            ? $structuredResponse['reasoning_summary']
            : null;

        $product = DB::transaction(function () use (
            $product,
            $contentHash,
            $hintHash,
            $code,
            $proposal,
            $preserveHumanAppliedTaxonomy,
            $gapSuggestion,
            $gapExplanation,
            $reasoning,
        ): Product {
            $fresh = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($fresh->taxonomyClassificationIsHumanLocked()) {
                if (! $preserveHumanAppliedTaxonomy) {
                    return $fresh;
                }

                $this->storePendingProposal(
                    $fresh,
                    $proposal,
                    $contentHash,
                    $hintHash,
                    [$code],
                    [$code],
                    $gapSuggestion,
                    $gapExplanation,
                    $reasoning,
                );

                return $fresh->fresh() ?? $fresh;
            }

            $fresh->taxonomy_classification_status = TaxonomyClassificationStatus::Failed;
            $fresh->taxonomy_classified_at = now();
            $fresh->taxonomy_content_fingerprint = $contentHash;
            $fresh->taxonomy_relationship_hint_fingerprint = $hintHash;
            $fresh->taxonomy_classification_version = (int) config('curated_catalog.taxonomy_classification.version', 1);
            $fresh->taxonomy_review_reasons = [$code];
            $fresh->taxonomy_classification_warnings = [$code];
            $fresh->taxonomy_gap_suggestion = $gapSuggestion;
            $fresh->taxonomy_gap_explanation = $gapExplanation;
            $fresh->taxonomy_reasoning = $reasoning;
            $fresh->taxonomy_classification_proposal = $proposal;
            $fresh->taxonomy_proposal_pending = false;
            $fresh->status = ProductStatus::Draft;
            $fresh->save();

            return $fresh->fresh() ?? $fresh;
        });

        $this->auditLatestIntakeItem($product, [$code], $product->taxonomy_classification_proposal ?? []);
        $this->logFailure($product, $code, $message);

        return new ClassifyCuratedMerchantProductResult(
            product: $product,
            status: $product->taxonomy_classification_status ?? TaxonomyClassificationStatus::Failed,
            classified: true,
            reason: $code,
            warnings: [$code],
            reviewReasons: [$code],
            proposal: null,
        );
    }

    /**
     * @param  array<string, mixed>  $proposalArray
     * @param  list<string>  $reviewReasons
     * @param  list<string>  $warnings
     * @param  array<string, string>|null  $reasoning
     */
    private function storePendingProposal(
        Product $product,
        array $proposalArray,
        string $contentHash,
        string $hintHash,
        array $reviewReasons,
        array $warnings,
        ?string $gapSuggestion,
        ?string $gapExplanation,
        ?array $reasoning,
    ): void {
        $product->taxonomy_classified_at = now();
        $product->taxonomy_content_fingerprint = $contentHash;
        $product->taxonomy_relationship_hint_fingerprint = $hintHash;
        $product->taxonomy_classification_version = (int) config('curated_catalog.taxonomy_classification.version', 1);
        $product->taxonomy_review_reasons = $reviewReasons !== [] ? $reviewReasons : null;
        $product->taxonomy_classification_warnings = $warnings !== [] ? $warnings : null;
        $product->taxonomy_gap_suggestion = $gapSuggestion;
        $product->taxonomy_gap_explanation = $gapExplanation;
        $product->taxonomy_reasoning = $reasoning;
        $product->taxonomy_classification_proposal = $proposalArray !== [] ? $proposalArray : null;
        $product->taxonomy_proposal_pending = true;
        $product->save();
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function structuredResponseSnapshot(CuratedProductEnrichmentResult $enrichment, array $warnings): array
    {
        return [
            'primary_category_id' => $enrichment->taxonomy->primaryCategoryId,
            'category_ids' => $enrichment->taxonomy->categoryIds,
            'relationship_ids' => $enrichment->taxonomy->relationshipIds,
            'recipient_type_ids' => $enrichment->taxonomy->recipientTypeIds,
            'occasion_ids' => $enrichment->taxonomy->occasionIds,
            'interest_ids' => $enrichment->taxonomy->interestIds,
            'profession_ids' => $enrichment->taxonomy->professionIds,
            'gift_type_ids' => $enrichment->taxonomy->giftTypeIds,
            'confidence' => $enrichment->confidence->toArray(),
            'reasoning_summary' => $enrichment->reasoningSummary,
            'taxonomy_gap' => $enrichment->taxonomyGap->toArray(),
            'exception_codes' => $enrichment->taxonomy->exceptionCodes,
            'rejected_ids' => $enrichment->taxonomy->rejectedIds,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param  array<string, mixed>  $structuredResponse
     * @return array<string, mixed>
     */
    private function proposalFieldsFromStructuredResponse(array $structuredResponse): array
    {
        $fields = [];

        foreach ([
            'primary_category_id',
            'category_ids',
            'relationship_ids',
            'recipient_type_ids',
            'occasion_ids',
            'interest_ids',
            'profession_ids',
            'gift_type_ids',
            'confidence',
            'reasoning_summary',
            'taxonomy_gap',
            'exception_codes',
            'rejected_ids',
        ] as $key) {
            if (array_key_exists($key, $structuredResponse)) {
                $fields[$key] = $structuredResponse[$key];
            }
        }

        return $fields;
    }

    private function failedCode(CommercialEnrichmentException $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'valid primary category')) {
            return TaxonomyClassificationWarningCode::MissingPrimaryCategory->value;
        }

        if (str_contains($message, 'malformed')) {
            return TaxonomyClassificationWarningCode::MalformedAiResponse->value;
        }

        return TaxonomyClassificationWarningCode::EnrichmentFailed->value;
    }

    /**
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $proposal
     */
    private function auditLatestIntakeItem(Product $product, array $warnings, array $proposal): void
    {
        $item = CuratedProductIntakeItem::query()
            ->where('product_id', $product->id)
            ->orderByDesc('id')
            ->first();

        if (! $item instanceof CuratedProductIntakeItem) {
            return;
        }

        $existing = is_array($item->warnings) ? $item->warnings : [];
        $item->warnings = array_values(array_unique(array_merge($existing, $warnings)));

        $payload = is_array($item->source_payload) ? $item->source_payload : [];
        $payload['taxonomy_classification_proposal'] = $proposal;
        $item->source_payload = $payload;
        $item->save();
    }

    private function logFailure(Product $product, string $code, string $message): void
    {
        $link = $product->affiliateLinks->firstWhere('is_primary', true)
            ?? $product->affiliateLinks->first();

        if ($link === null && ! $product->relationLoaded('affiliateLinks')) {
            $link = $product->affiliateLinks()
                ->with('merchant:id,slug')
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->first();
        } elseif ($link !== null && ! $link->relationLoaded('merchant')) {
            $link->load('merchant:id,slug');
        }

        Log::warning('Curated product classification failed', [
            'product_id' => $product->id,
            'merchant' => $link?->merchant?->slug,
            'external_id' => $link?->external_product_id,
            'source_title' => $this->contentFingerprint->sourceTitle($product),
            'classification_status' => TaxonomyClassificationStatus::Failed->value,
            'failure_category' => $code,
            'failure_detail' => $message,
        ]);
    }
}
