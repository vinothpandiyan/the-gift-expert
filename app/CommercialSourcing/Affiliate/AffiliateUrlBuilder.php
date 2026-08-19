<?php

namespace App\CommercialSourcing\Affiliate;

use App\CommercialSourcing\SourcedMerchantOffer;

interface AffiliateUrlBuilder
{
    /**
     * @param  array<string, mixed>  $merchantConfig
     */
    public function build(SourcedMerchantOffer $offer, array $merchantConfig): AffiliateUrlResult;
}
