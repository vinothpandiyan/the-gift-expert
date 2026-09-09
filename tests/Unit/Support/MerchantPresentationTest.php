<?php

namespace Tests\Unit\Support;

use App\Models\Merchant;
use App\Support\MerchantPresentation;
use Tests\TestCase;

class MerchantPresentationTest extends TestCase
{
    public function test_amazon_india_cta_uses_amazon_without_india(): void
    {
        $merchant = new Merchant([
            'name' => 'Amazon India',
            'slug' => 'amazon-in',
            'affiliate_network' => 'amazon_associates',
        ]);

        $this->assertSame('Amazon India', MerchantPresentation::listingName($merchant));
        $this->assertSame('Amazon', MerchantPresentation::dealBrandName($merchant));
        $this->assertSame('View deal on Amazon', MerchantPresentation::outboundCtaLabel($merchant));
        $this->assertTrue(MerchantPresentation::isAmazon($merchant));
    }

    public function test_non_amazon_merchants_keep_their_listing_name(): void
    {
        $merchant = new Merchant([
            'name' => 'Example Merchant',
            'slug' => 'example-merchant',
            'affiliate_network' => 'example',
        ]);

        $this->assertSame('View deal on Example Merchant', MerchantPresentation::outboundCtaLabel($merchant));
        $this->assertFalse(MerchantPresentation::isAmazon($merchant));
    }
}
