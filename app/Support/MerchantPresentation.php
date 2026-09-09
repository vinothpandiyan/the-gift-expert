<?php

namespace App\Support;

use App\Models\Merchant;

final class MerchantPresentation
{
    public static function listingName(?Merchant $merchant): ?string
    {
        $name = $merchant?->name;

        return filled($name) ? (string) $name : null;
    }

    public static function dealBrandName(?Merchant $merchant): ?string
    {
        if ($merchant === null) {
            return null;
        }

        if (self::isAmazon($merchant)) {
            return 'Amazon';
        }

        return self::listingName($merchant);
    }

    public static function outboundCtaLabel(?Merchant $merchant): string
    {
        $brand = self::dealBrandName($merchant);

        return $brand !== null ? 'View deal on '.$brand : 'View deal';
    }

    public static function isAmazon(?Merchant $merchant): bool
    {
        if ($merchant === null) {
            return false;
        }

        return str_starts_with((string) $merchant->slug, 'amazon-')
            || $merchant->affiliate_network === 'amazon_associates';
    }
}
