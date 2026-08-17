<?php

namespace Tests\Unit\Actions\Import;

use App\Actions\Import\UpsertImportedProductAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Import\ImportedCatalogItem;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpsertImportedProductActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_draft_product_and_primary_affiliate_link(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Fake Merchant',
            'slug' => 'fake-merchant',
            'affiliate_network' => 'fake',
        ]);

        $link = app(UpsertImportedProductAction::class)->execute($merchant, new ImportedCatalogItem(
            name: 'Classic Leather Wallet',
            description: 'A wallet.',
            short_description: 'Slim wallet.',
            brand: 'Example Brand',
            price_amount: '1899.00',
            price_currency: 'INR',
            affiliate_url: 'https://example.test/affiliate/wallet',
            external_product_id: 'FAKE-WALLET-1',
            image_urls: ['https://example.test/images/wallet-1.jpg'],
            raw: ['external_product_id' => 'FAKE-WALLET-1'],
        ));

        $product = $link->product;

        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertNull($product->published_at);
        $this->assertSame('classic-leather-wallet', $product->slug);
        $this->assertTrue($link->is_primary);
        $this->assertSame(AffiliateLinkStatus::Active, $link->status);
        $this->assertSame($merchant->id, $link->merchant_id);
        $this->assertSame(0, $product->images()->count());
    }

    public function test_it_suffixes_slugs_when_the_base_slug_is_taken(): void
    {
        Product::query()->create([
            'name' => 'Existing Wallet',
            'slug' => 'classic-leather-wallet',
            'status' => ProductStatus::Draft,
        ]);

        $merchant = Merchant::query()->create([
            'name' => 'Fake Merchant',
            'slug' => 'fake-merchant',
            'affiliate_network' => 'fake',
        ]);

        $link = app(UpsertImportedProductAction::class)->execute($merchant, new ImportedCatalogItem(
            name: 'Classic Leather Wallet',
            description: null,
            short_description: null,
            brand: null,
            price_amount: '10.00',
            price_currency: 'INR',
            affiliate_url: 'https://example.test/affiliate/wallet',
            external_product_id: 'FAKE-WALLET-1',
            image_urls: [],
            raw: [],
        ));

        $this->assertSame('classic-leather-wallet-2', $link->product->slug);
    }
}
