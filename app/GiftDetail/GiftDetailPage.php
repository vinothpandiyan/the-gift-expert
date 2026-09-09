<?php

namespace App\GiftDetail;

use App\Models\AffiliateLink;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\DiscoveryUrl;
use App\Support\MerchantPresentation;
use Illuminate\Support\Collection;

final class GiftDetailPage
{
    /**
     * @param  Collection<int, ProductImage>  $galleryImages
     * @param  Collection<int, AffiliateLink>  $merchantOffers
     * @param  list<string>  $whyItems
     * @param  list<array{label: string, items: list<array{label: string, url: string}>}>  $bestForGroups
     * @param  list<array{label: string, value: string}>  $giftDetailRows
     */
    public function __construct(
        public readonly Product $product,
        public readonly Collection $galleryImages,
        public readonly ?AffiliateLink $primaryAffiliateLink,
        public readonly Collection $merchantOffers,
        public readonly ?string $priceLabel,
        public readonly ?string $badge,
        public readonly array $whyItems,
        public readonly array $bestForGroups,
        public readonly array $giftDetailRows,
    ) {}

    public function primaryMerchantName(): ?string
    {
        $name = $this->primaryAffiliateLink?->merchant?->name;

        return filled($name) ? (string) $name : null;
    }

    public function primaryCtaLabel(): string
    {
        return MerchantPresentation::outboundCtaLabel($this->primaryAffiliateLink?->merchant);
    }

    public function outboundUrl(): ?string
    {
        if ($this->primaryAffiliateLink === null) {
            return null;
        }

        return DiscoveryUrl::affiliateOut($this->primaryAffiliateLink->uuid);
    }

    public function whyAsList(): bool
    {
        return count($this->whyItems) > 1;
    }

    public function hasWhy(): bool
    {
        return $this->whyItems !== [];
    }
}
