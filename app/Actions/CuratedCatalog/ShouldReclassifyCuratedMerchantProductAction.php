<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\ShouldReclassifyDecision;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Product;

class ShouldReclassifyCuratedMerchantProductAction
{
    public function __construct(
        private BuildCuratedTaxonomyContentFingerprintAction $contentFingerprint,
        private BuildCuratedRelationshipHintFingerprintAction $hintFingerprint,
    ) {}

    public function execute(Product $product, bool $force = false, bool $retryFailed = false): ShouldReclassifyDecision
    {
        $status = $product->taxonomy_classification_status ?? TaxonomyClassificationStatus::None;

        if ($force) {
            return new ShouldReclassifyDecision(true, 'forced');
        }

        if ($status->isHumanLocked()) {
            return new ShouldReclassifyDecision(false, 'human_locked');
        }

        if ($product->status === ProductStatus::Archived) {
            return new ShouldReclassifyDecision(false, 'archived');
        }

        if ($status === TaxonomyClassificationStatus::None) {
            return new ShouldReclassifyDecision(true, 'none');
        }

        if ($status === TaxonomyClassificationStatus::Failed) {
            if ($retryFailed) {
                return new ShouldReclassifyDecision(true, 'retry_failed');
            }

            if ($this->versionBehind($product) && $status->isAiManaged()) {
                return new ShouldReclassifyDecision(true, 'classification_version');
            }

            return new ShouldReclassifyDecision(false, 'failed_not_retried');
        }

        if ($status === TaxonomyClassificationStatus::AiAccepted && $this->missingPrimaryCategory($product)) {
            return new ShouldReclassifyDecision(true, 'missing_primary_category');
        }

        if ($this->versionBehind($product) && $status->isAiManaged()) {
            return new ShouldReclassifyDecision(true, 'classification_version');
        }

        $content = $this->contentFingerprint->execute($product);

        if (is_string($product->taxonomy_content_fingerprint)
            && $product->taxonomy_content_fingerprint !== ''
            && $product->taxonomy_content_fingerprint !== $content) {
            return new ShouldReclassifyDecision(true, 'content_fingerprint');
        }

        $hints = $this->hintFingerprint->execute($product);

        if (is_string($product->taxonomy_relationship_hint_fingerprint)
            && $product->taxonomy_relationship_hint_fingerprint !== ''
            && $product->taxonomy_relationship_hint_fingerprint !== $hints) {
            return new ShouldReclassifyDecision(true, 'relationship_hints');
        }

        if ($product->taxonomy_content_fingerprint === null || $product->taxonomy_relationship_hint_fingerprint === null) {
            if (in_array($status, [
                TaxonomyClassificationStatus::AiAccepted,
                TaxonomyClassificationStatus::Review,
            ], true)) {
                return new ShouldReclassifyDecision(false, 'current');
            }
        }

        return new ShouldReclassifyDecision(false, 'current');
    }

    private function versionBehind(Product $product): bool
    {
        $current = (int) config('curated_catalog.taxonomy_classification.version', 1);
        $stored = $product->taxonomy_classification_version;

        return $stored === null || (int) $stored < $current;
    }

    private function missingPrimaryCategory(Product $product): bool
    {
        if ($product->relationLoaded('categories')) {
            return ! $product->categories->contains(
                fn ($category): bool => (bool) $category->pivot?->is_primary,
            );
        }

        return ! $product->categories()
            ->wherePivot('is_primary', true)
            ->exists();
    }
}
