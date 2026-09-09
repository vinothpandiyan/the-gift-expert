<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\CuratedMerchantProductInput;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use InvalidArgumentException;

class BuildCuratedMerchantProductInputFromProductAction
{
    public function __construct(
        private BuildCuratedTaxonomyContentFingerprintAction $contentFingerprint,
    ) {}

    /**
     * @return array{0: Merchant, 1: CuratedMerchantProductInput, 2: AffiliateLink}
     */
    public function execute(Product $product): array
    {
        $link = $product->affiliateLinks()
            ->with('merchant')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        if (! $link instanceof AffiliateLink || ! $link->merchant instanceof Merchant) {
            throw new InvalidArgumentException('The product has no merchant affiliate identity to classify.');
        }

        $input = new CuratedMerchantProductInput(
            itemIndex: 0,
            merchantSlug: (string) $link->merchant->slug,
            externalProductId: (string) $link->external_product_id,
            sourceUrl: (string) $link->url,
            title: $this->contentFingerprint->sourceTitle($product),
            priceAmount: $product->price_amount !== null ? (string) $product->price_amount : null,
            priceCurrency: $product->price_currency,
            sourceImageUrl: null,
            availability: $link->availability,
            capturedAt: $link->last_seen_at?->toIso8601String(),
            curationGroup: null,
            sourcePayload: [
                'title' => $this->contentFingerprint->sourceTitle($product),
                'source_url' => $link->url,
            ],
        );

        return [$link->merchant, $input, $link];
    }
}
