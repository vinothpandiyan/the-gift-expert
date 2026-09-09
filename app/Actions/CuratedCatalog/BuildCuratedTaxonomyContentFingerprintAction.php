<?php

namespace App\Actions\CuratedCatalog;

use App\Models\AffiliateLink;
use App\Models\CuratedProductIntakeItem;
use App\Models\Product;

class BuildCuratedTaxonomyContentFingerprintAction
{
    public function execute(Product $product): string
    {
        $link = $this->primaryLink($product);

        if ($link instanceof AffiliateLink && ! $link->relationLoaded('merchant')) {
            $link->load('merchant:id,slug');
        }

        $payload = [
            'merchant' => $link?->merchant?->slug,
            'external_product_id' => $link?->external_product_id,
            'source_title' => $this->sourceTitle($product),
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function sourceTitle(Product $product): string
    {
        $fromIntake = $this->latestIntakeTitle($product);

        if ($fromIntake !== null) {
            return $fromIntake;
        }

        $proposal = $product->taxonomy_classification_proposal;
        $fromProposal = is_array($proposal) ? ($proposal['source_title'] ?? null) : null;

        if (is_string($fromProposal) && trim($fromProposal) !== '') {
            return trim($fromProposal);
        }

        return trim((string) $product->name);
    }

    private function latestIntakeTitle(Product $product): ?string
    {
        $payload = CuratedProductIntakeItem::query()
            ->where('product_id', $product->id)
            ->orderByDesc('id')
            ->value('source_payload');

        if (! is_array($payload)) {
            return null;
        }

        $title = $payload['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            return null;
        }

        return trim($title);
    }

    private function primaryLink(Product $product): ?AffiliateLink
    {
        if ($product->relationLoaded('affiliateLinks')) {
            $link = $product->affiliateLinks->firstWhere('is_primary', true)
                ?? $product->affiliateLinks->first();

            return $link instanceof AffiliateLink ? $link : null;
        }

        $link = $product->affiliateLinks()
            ->with('merchant:id,slug')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        return $link instanceof AffiliateLink ? $link : null;
    }
}
