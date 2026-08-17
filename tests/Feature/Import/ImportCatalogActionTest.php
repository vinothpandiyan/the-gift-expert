<?php

namespace Tests\Feature\Import;

use App\Actions\Import\ImportCatalogAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ImportRunItemStatus;
use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\Support\FakesCatalogImportImages;
use Tests\TestCase;

class ImportCatalogActionTest extends TestCase
{
    use FakesCatalogImportImages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeCatalogImageHttp();
    }

    public function test_first_import_creates_two_draft_products_and_links(): void
    {
        $merchant = $this->fakeMerchant();

        $run = app(ImportCatalogAction::class)->execute($merchant);

        $products = Product::query()->orderBy('id')->get();
        $links = AffiliateLink::query()->orderBy('id')->get();

        $this->assertSame(ImportRunStatus::CompletedWithErrors, $run->status);
        $this->assertSame(5, $run->items_total);
        $this->assertSame(2, $run->items_succeeded);
        $this->assertSame(3, $run->items_skipped);
        $this->assertSame(0, $run->items_failed);

        $this->assertCount(2, $products);
        $this->assertCount(2, $links);

        $this->assertTrue($products->every(fn (Product $product): bool => $product->status === ProductStatus::Draft));
        $this->assertTrue($products->every(fn (Product $product): bool => $product->published_at === null));
        $this->assertNotSame($products[0]->slug, $products[1]->slug);
        $this->assertNotEmpty($products[0]->slug);
        $this->assertNotEmpty($products[1]->slug);

        $this->assertTrue($links->every(fn (AffiliateLink $link): bool => $link->merchant_id === $merchant->id));
        $this->assertEqualsCanonicalizing(
            ['FAKE-WALLET-1', 'FAKE-COFFEE-1'],
            $links->pluck('external_product_id')->all(),
        );
        $this->assertTrue($links->every(fn (AffiliateLink $link): bool => $link->is_primary));
        $this->assertTrue($links->every(fn (AffiliateLink $link): bool => $link->status === AffiliateLinkStatus::Active));
        $this->assertNotNull($links[0]->uuid);
        $this->assertNotNull($links[1]->uuid);
        $this->assertNotSame($links[0]->uuid, $links[1]->uuid);
    }

    public function test_import_is_idempotent_and_updates_price_and_url(): void
    {
        $merchant = $this->fakeMerchant();
        $action = app(ImportCatalogAction::class);

        $first = $action->execute($merchant);
        $products = Product::query()->orderBy('id')->get();
        $links = AffiliateLink::query()->orderBy('id')->get();

        config()->set('import.providers.fake.fixture', base_path('tests/Fixtures/import/catalog-updated.json'));

        $second = $action->execute($merchant);

        $this->assertCount(2, Product::query()->get());
        $this->assertCount(2, AffiliateLink::query()->get());
        $this->assertEquals($products->pluck('id')->all(), Product::query()->orderBy('id')->pluck('id')->all());
        $this->assertEquals($links->pluck('id')->all(), AffiliateLink::query()->orderBy('id')->pluck('id')->all());
        $this->assertEquals($links->pluck('uuid')->all(), AffiliateLink::query()->orderBy('id')->pluck('uuid')->all());

        $wallet = AffiliateLink::query()->where('external_product_id', 'FAKE-WALLET-1')->firstOrFail();
        $this->assertSame('https://example.test/affiliate/wallet-updated', $wallet->url);
        $this->assertSame('2099.00', $wallet->product->price_amount);
        $this->assertSame(2, $first->items_succeeded);
        $this->assertSame(2, $second->items_succeeded);
        $this->assertSame(ImportRunStatus::Completed, $second->status);
    }

    public function test_draft_reimport_updates_copy_but_preserves_slug(): void
    {
        $merchant = $this->fakeMerchant();
        app(ImportCatalogAction::class)->execute($merchant);

        $wallet = Product::query()->whereHas('affiliateLinks', function ($query): void {
            $query->where('external_product_id', 'FAKE-WALLET-1');
        })->firstOrFail();
        $slug = $wallet->slug;

        config()->set('import.providers.fake.fixture', base_path('tests/Fixtures/import/catalog-updated.json'));
        app(ImportCatalogAction::class)->execute($merchant);

        $wallet->refresh();

        $this->assertSame($slug, $wallet->slug);
        $this->assertSame(ProductStatus::Draft, $wallet->status);
        $this->assertSame('Updated Leather Wallet', $wallet->name);
        $this->assertSame('Updated wallet copy.', $wallet->short_description);
        $this->assertSame('Updated wallet description from a later catalog snapshot.', $wallet->description);
        $this->assertSame('Revised Brand', $wallet->brand);
    }

    public function test_published_reimport_updates_only_price_and_affiliate_fields(): void
    {
        $merchant = $this->fakeMerchant();
        app(ImportCatalogAction::class)->execute($merchant);

        $wallet = Product::query()->whereHas('affiliateLinks', function ($query): void {
            $query->where('external_product_id', 'FAKE-WALLET-1');
        })->firstOrFail();

        $wallet->update([
            'status' => ProductStatus::Published,
            'published_at' => now(),
            'meta_title' => 'Editorial wallet title',
            'meta_description' => 'Editorial wallet description',
        ]);

        $original = $wallet->fresh();

        config()->set('import.providers.fake.fixture', base_path('tests/Fixtures/import/catalog-updated.json'));
        app(ImportCatalogAction::class)->execute($merchant);

        $wallet->refresh();
        $link = $wallet->affiliateLinks()->where('external_product_id', 'FAKE-WALLET-1')->firstOrFail();

        $this->assertSame($original->name, $wallet->name);
        $this->assertSame($original->slug, $wallet->slug);
        $this->assertSame($original->short_description, $wallet->short_description);
        $this->assertSame($original->description, $wallet->description);
        $this->assertSame($original->brand, $wallet->brand);
        $this->assertSame($original->meta_title, $wallet->meta_title);
        $this->assertSame($original->meta_description, $wallet->meta_description);
        $this->assertSame(ProductStatus::Published, $wallet->status);
        $this->assertSame('2099.00', $wallet->price_amount);
        $this->assertSame('https://example.test/affiliate/wallet-updated', $link->url);
        $this->assertSame(AffiliateLinkStatus::Active, $link->status);
        $this->assertNotNull($link->last_verified_at);
    }

    public function test_missing_required_fields_are_skipped_without_creating_products(): void
    {
        $merchant = $this->fakeMerchant();

        $run = app(ImportCatalogAction::class)->execute($merchant);

        $this->assertDatabaseMissing('products', ['name' => 'Gift Without Identity']);
        $this->assertDatabaseMissing('products', ['name' => 'Gift Without Affiliate URL']);
        $this->assertDatabaseMissing('affiliate_links', ['external_product_id' => 'FAKE-MISSING-NAME']);
        $this->assertDatabaseMissing('affiliate_links', ['external_product_id' => 'FAKE-MISSING-URL']);
        $this->assertSame(3, $run->items_skipped);

        $this->assertDatabaseHas('import_run_items', [
            'external_product_id' => 'FAKE-MISSING-NAME',
            'status' => ImportRunItemStatus::Skipped->value,
        ]);
        $this->assertDatabaseHas('import_run_items', [
            'external_product_id' => 'FAKE-MISSING-URL',
            'status' => ImportRunItemStatus::Skipped->value,
        ]);
        $this->assertDatabaseMissing('import_run_items', [
            'import_run_id' => $run->id,
            'external_product_id' => '',
        ]);
    }

    public function test_soft_deleted_affiliate_link_is_restored_instead_of_duplicated(): void
    {
        $merchant = $this->fakeMerchant();
        app(ImportCatalogAction::class)->execute($merchant);

        $link = AffiliateLink::query()->where('external_product_id', 'FAKE-WALLET-1')->firstOrFail();
        $uuid = $link->uuid;
        $id = $link->id;
        $link->delete();

        $this->assertSoftDeleted('affiliate_links', ['id' => $id]);

        config()->set('import.providers.fake.fixture', base_path('tests/Fixtures/import/catalog-updated.json'));
        app(ImportCatalogAction::class)->execute($merchant);

        $this->assertSame(1, AffiliateLink::withTrashed()->where('external_product_id', 'FAKE-WALLET-1')->count());

        $restored = AffiliateLink::query()->where('external_product_id', 'FAKE-WALLET-1')->firstOrFail();
        $this->assertSame($id, $restored->id);
        $this->assertSame($uuid, $restored->uuid);
        $this->assertNull($restored->deleted_at);
        $this->assertSame('https://example.test/affiliate/wallet-updated', $restored->url);
        $this->assertSame(AffiliateLinkStatus::Active, $restored->status);
    }

    public function test_unknown_provider_key_fails_without_creating_products(): void
    {
        $merchant = Merchant::query()->create([
            'name' => 'Unknown Network',
            'slug' => 'unknown-network',
            'affiliate_network' => 'not_a_provider',
        ]);

        try {
            app(ImportCatalogAction::class)->execute($merchant);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('not_a_provider', $exception->getMessage());
        }

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, AffiliateLink::query()->count());
        $this->assertDatabaseHas('import_runs', [
            'merchant_id' => $merchant->id,
            'provider_key' => 'not_a_provider',
            'status' => ImportRunStatus::Failed->value,
        ]);
    }

    public function test_import_does_not_attach_taxonomies(): void
    {
        $merchant = $this->fakeMerchant();

        app(ImportCatalogAction::class)->execute($merchant);

        foreach (Product::query()->get() as $product) {
            $this->assertCount(0, $product->categories);
            $this->assertCount(0, $product->occasions);
            $this->assertCount(0, $product->relationships);
            $this->assertCount(0, $product->recipientTypes);
            $this->assertCount(0, $product->interests);
            $this->assertCount(0, $product->professions);
            $this->assertCount(0, $product->giftTypes);
        }
    }

    public function test_discovery_route_count_is_unchanged(): void
    {
        $discoveryRoutes = collect(Route::getRoutes())
            ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'));

        $this->assertCount(14, $discoveryRoutes);
        $this->assertFalse(Route::has('products.index'));
    }

    private function fakeMerchant(): Merchant
    {
        return Merchant::query()->create([
            'name' => 'Fake Merchant',
            'slug' => 'fake-merchant',
            'affiliate_network' => 'fake',
        ]);
    }
}
