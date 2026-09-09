<?php

namespace App\Actions\CuratedCatalog;

use App\Models\Product;

class DetectStaleCuratedTaxonomyProposalAction
{
    public function __construct(
        private BuildCuratedTaxonomyContentFingerprintAction $contentFingerprint,
        private BuildCuratedRelationshipHintFingerprintAction $hintFingerprint,
    ) {}

    public function execute(Product $product): bool
    {
        return $this->reason($product) !== null;
    }

    public function reason(Product $product): ?string
    {
        $currentVersion = (int) config('curated_catalog.taxonomy_classification.version', 1);
        $storedVersion = $product->taxonomy_classification_version;

        if ($storedVersion === null || (int) $storedVersion !== $currentVersion) {
            return 'classification_version';
        }

        $proposal = $product->taxonomy_classification_proposal;
        $proposalVersion = is_array($proposal) ? ($proposal['classification_version'] ?? null) : null;

        if ($proposalVersion !== null && (int) $proposalVersion !== $currentVersion) {
            return 'classification_version';
        }

        $content = $this->contentFingerprint->execute($product);

        if (! is_string($product->taxonomy_content_fingerprint) || $product->taxonomy_content_fingerprint === '') {
            return 'content_fingerprint';
        }

        if ($product->taxonomy_content_fingerprint !== $content) {
            return 'content_fingerprint';
        }

        $hints = $this->hintFingerprint->execute($product);

        if (! is_string($product->taxonomy_relationship_hint_fingerprint) || $product->taxonomy_relationship_hint_fingerprint === '') {
            return 'relationship_hints';
        }

        if ($product->taxonomy_relationship_hint_fingerprint !== $hints) {
            return 'relationship_hints';
        }

        return null;
    }
}
