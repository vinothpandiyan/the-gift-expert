<?php

namespace App\Actions\Affiliate;

use App\Models\AffiliateClick;
use App\Models\AffiliateLink;

class CreateAffiliateClickAction
{
    public function execute(AffiliateLink $affiliateLink): AffiliateClick
    {
        return AffiliateClick::query()->create([
            'affiliate_link_id' => $affiliateLink->id,
            'product_id' => $affiliateLink->product_id,
            'recommendation_session_id' => null,
            'recommendation_result_id' => null,
            'ip_hash' => null,
        ]);
    }
}
