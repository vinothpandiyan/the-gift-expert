<?php

namespace Tests\Unit\Actions\Product;

use App\Actions\Product\AssessProductPublicationRequirementsAction;
use App\Enums\AffiliateLinkStatus;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessProductPublicationRequirementsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_blocking_codes_for_missing_requirements(): void
    {
        $product = Product::factory()->create([
            'name' => '',
            'slug' => '',
            'price_amount' => null,
        ]);

        $result = app(AssessProductPublicationRequirementsAction::class)->execute($product);

        $this->assertContains('missing_name', $result['error_codes']);
        $this->assertContains('missing_slug', $result['error_codes']);
        $this->assertContains('no_image', $result['error_codes']);
        $this->assertContains('no_active_affiliate_link', $result['error_codes']);
        $this->assertContains('missing_or_ambiguous_price', $result['warnings']);
        $this->assertContains('missing_primary_category', $result['warnings']);
    }

    public function test_it_returns_no_errors_for_publishable_product(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Merchant',
            'slug' => 'merchant',
            'affiliate_network' => 'fake',
        ]);

        $product = Product::factory()->create([
            'price_amount' => '100.00',
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'images/gift.jpg',
            'is_primary' => true,
        ]);

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://merchant.example/product',
            'external_product_id' => 'SKU123',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);

        $result = app(AssessProductPublicationRequirementsAction::class)->execute($product->fresh());

        $this->assertSame([], $result['error_codes']);
        $this->assertContains('missing_primary_category', $result['warnings']);
    }
}
