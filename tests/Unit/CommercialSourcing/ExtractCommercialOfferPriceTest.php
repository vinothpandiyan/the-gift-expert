<?php

namespace Tests\Unit\CommercialSourcing;

use App\CommercialSourcing\ExtractCommercialOfferPrice;
use Tests\TestCase;

class ExtractCommercialOfferPriceTest extends TestCase
{
    public function test_it_extracts_a_single_rupee_price(): void
    {
        $price = app(ExtractCommercialOfferPrice::class)->execute('Buy BrandX French Press for ₹1,299 today.');

        $this->assertSame(['amount' => '1299.00', 'currency' => 'INR'], $price);
    }

    public function test_it_extracts_rs_prefixed_prices(): void
    {
        $price = app(ExtractCommercialOfferPrice::class)->execute('Available at Rs. 499');

        $this->assertSame(['amount' => '499.00', 'currency' => 'INR'], $price);
    }

    public function test_ambiguous_multiple_prices_are_rejected(): void
    {
        $price = app(ExtractCommercialOfferPrice::class)->execute('Was ₹1,999 now ₹1,299');

        $this->assertNull($price);
    }

    public function test_repeated_same_price_is_accepted(): void
    {
        $price = app(ExtractCommercialOfferPrice::class)->execute('Price ₹999 MRP ₹999');

        $this->assertSame(['amount' => '999.00', 'currency' => 'INR'], $price);
    }

    public function test_title_and_snippet_conflicts_are_ambiguous(): void
    {
        $price = app(ExtractCommercialOfferPrice::class)->execute('BrandX French Press ₹1,299 Available at Rs. 499');

        $this->assertNull($price);
    }

    public function test_missing_price_is_null(): void
    {
        $this->assertNull(app(ExtractCommercialOfferPrice::class)->execute('A stainless steel french press.'));
    }
}
