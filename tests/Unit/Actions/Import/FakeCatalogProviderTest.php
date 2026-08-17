<?php

namespace Tests\Unit\Actions\Import;

use App\Import\FakeCatalogProvider;
use App\Import\ImportedCatalogItem;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeCatalogProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_yields_two_valid_normalized_catalog_items(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Fake Merchant',
            'slug' => 'fake-merchant',
            'affiliate_network' => 'fake',
        ]);

        $items = iterator_to_array(app(FakeCatalogProvider::class)->eachProduct($merchant), false);
        $valid = array_values(array_filter(
            $items,
            fn (ImportedCatalogItem $item): bool => filled($item->external_product_id)
                && filled($item->name)
                && filled($item->affiliate_url),
        ));

        $this->assertCount(5, $items);
        $this->assertCount(2, $valid);

        $this->assertSame('Classic Leather Wallet', $valid[0]->name);
        $this->assertSame('A slim leather wallet for everyday carry.', $valid[0]->short_description);
        $this->assertSame('Imported sample wallet with a deterministic fake catalog identity.', $valid[0]->description);
        $this->assertSame('Example Brand', $valid[0]->brand);
        $this->assertSame('1899.00', $valid[0]->price_amount);
        $this->assertSame('INR', $valid[0]->price_currency);
        $this->assertSame('https://example.test/affiliate/wallet', $valid[0]->affiliate_url);
        $this->assertSame('FAKE-WALLET-1', $valid[0]->external_product_id);
        $this->assertSame([
            'https://example.test/images/wallet-1.jpg',
            'https://example.test/images/wallet-2.jpg',
        ], $valid[0]->image_urls);
        $this->assertSame('FAKE-WALLET-1', $valid[0]->raw['external_product_id']);

        $this->assertSame('Pour-Over Coffee Kit', $valid[1]->name);
        $this->assertSame('FAKE-COFFEE-1', $valid[1]->external_product_id);
        $this->assertSame('1299.00', $valid[1]->price_amount);
        $this->assertSame(['https://example.test/images/coffee-kit.jpg'], $valid[1]->image_urls);
    }
}
