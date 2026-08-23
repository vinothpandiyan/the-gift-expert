<?php

namespace App\CuratedCatalog\Affiliate;

use App\CommercialSourcing\Affiliate\AffiliateUrlResult;
use App\CommercialSourcing\Affiliate\ConfigAffiliateUrlBuilder;
use App\CommercialSourcing\CommercialSourcingMerchants;
use App\CommercialSourcing\SourcedMerchantOffer;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\Enums\CommercialExternalIdSource;
use App\Models\Merchant;
use App\Support\CatalogCandidateSourceUrl;

class BuildCuratedAffiliateUrlAction
{
    public function __construct(
        private CommercialSourcingMerchants $merchants,
        private ConfigAffiliateUrlBuilder $affiliateUrlBuilder,
    ) {}

    public function execute(Merchant $merchant, CuratedMerchantProductInput $input): AffiliateUrlResult
    {
        $config = $this->merchants->configForSlug($merchant->slug) ?? [];

        return $this->affiliateUrlBuilder->build($this->toOffer($merchant, $input), $config);
    }

    private function toOffer(Merchant $merchant, CuratedMerchantProductInput $input): SourcedMerchantOffer
    {
        $normalizedUrl = CatalogCandidateSourceUrl::normalize($input->sourceUrl) ?? $input->sourceUrl;
        $imageUrls = $input->sourceImageUrl !== null ? [$input->sourceImageUrl] : [];

        return new SourcedMerchantOffer(
            merchantId: $merchant->id,
            merchantSlug: $merchant->slug,
            sourceUrl: $input->sourceUrl,
            normalizedUrl: $normalizedUrl,
            title: $input->title,
            snippet: $input->title,
            externalProductId: $input->externalProductId,
            externalIdSource: CommercialExternalIdSource::Extracted,
            priceAmount: $input->priceAmount,
            priceCurrency: $input->priceCurrency,
            imageUrls: $imageUrls,
            retrievedAt: $input->capturedAt,
            sourceEvidence: [],
            rankScore: 0,
        );
    }
}
