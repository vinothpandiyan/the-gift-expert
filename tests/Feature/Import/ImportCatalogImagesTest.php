<?php

namespace Tests\Feature\Import;

use App\Actions\Import\ImportCatalogAction;
use App\Actions\Import\StoreImportedProductImagesAction;
use App\Enums\ImportRunItemStatus;
use App\Import\ProviderImagePolicy;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesCatalogImportImages;
use Tests\TestCase;

class ImportCatalogImagesTest extends TestCase
{
    use FakesCatalogImportImages;
    use RefreshDatabase;

    public function test_policy_true_stores_processed_images_with_provenance(): void
    {
        $hashes = $this->fakeCatalogImageHttp();
        $merchant = $this->fakeMerchant();

        app(ImportCatalogAction::class)->execute($merchant);

        $wallet = $this->productByExternalId('FAKE-WALLET-1');
        $images = $wallet->images()->orderBy('sort_order')->get();

        $this->assertCount(2, $images);
        $this->assertTrue($images[0]->is_primary);
        $this->assertFalse($images[1]->is_primary);
        $this->assertSame($wallet->name, $images[0]->alt_text);
        $this->assertSame('https://example.test/images/wallet-1.jpg', $images[0]->source_url);
        $this->assertSame($hashes['https://example.test/images/wallet-1.jpg'], $images[0]->content_hash);
        $this->assertNotNull($images[0]->acquired_at);
        $this->assertMatchesRegularExpression(
            '#^products/'.$wallet->id.'/images/[0-9a-f-]{36}\.webp$#',
            $images[0]->path,
        );
        $this->assertTrue(Storage::disk('public')->exists($images[0]->path));
        $this->assertSame(20, (int) config('media.product_images.max_images_per_product'));
    }

    public function test_second_identical_import_does_not_duplicate_images(): void
    {
        $this->fakeCatalogImageHttp();
        $merchant = $this->fakeMerchant();
        $action = app(ImportCatalogAction::class);

        $action->execute($merchant);
        $action->execute($merchant);

        $wallet = $this->productByExternalId('FAKE-WALLET-1');
        $this->assertCount(2, $wallet->images);
        $this->assertCount(3, ProductImage::query()->get());
    }

    public function test_same_hash_on_different_products_creates_separate_rows(): void
    {
        $body = (string) file_get_contents($this->rasterImagePath(500, 500, 'jpeg'));
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/shared.jpg' => Http::response($body, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $first = Product::factory()->create(['name' => 'First Gift']);
        $second = Product::factory()->create(['name' => 'Second Gift']);
        $policy = ProviderImagePolicy::forKey('fake');
        $action = app(StoreImportedProductImagesAction::class);

        $action->execute($first, ['https://example.test/shared.jpg'], $policy);
        $action->execute($second, ['https://example.test/shared.jpg'], $policy);

        $hash = hash('sha256', $body);
        $this->assertSame(2, ProductImage::query()->where('content_hash', $hash)->count());
        $this->assertSame(1, $first->images()->count());
        $this->assertSame(1, $second->images()->count());
    }

    public function test_more_than_five_urls_stores_at_most_five(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();

        $urls = [];
        $fakes = [];

        for ($i = 1; $i <= 6; $i++) {
            $url = 'https://example.test/images/extra-'.$i.'.jpg';
            $urls[] = $url;
            $fakes[$url] = Http::response(
                (string) file_get_contents($this->rasterImagePath(300 + $i, 300 + $i, 'jpeg')),
                200,
                ['Content-Type' => 'image/jpeg'],
            );
        }

        Http::fake($fakes);

        $product = Product::factory()->create(['name' => 'Many Images Gift']);
        app(StoreImportedProductImagesAction::class)->execute(
            $product,
            $urls,
            ProviderImagePolicy::forKey('fake'),
        );

        $this->assertCount(5, $product->images);
        $this->assertSame(20, (int) config('media.product_images.max_images_per_product'));
    }

    public function test_policy_false_creates_no_images_and_does_not_make_http_requests(): void
    {
        Storage::fake('public');
        Http::fake(function () {
            $this->fail('HTTP should not be called when local acquisition is not permitted.');
        });

        config()->set('import.providers.fake.policy.store_images', false);
        config()->set('import.providers.fake.policy.transform_images', true);

        $merchant = $this->fakeMerchant();
        $run = app(ImportCatalogAction::class)->execute($merchant);

        $this->assertSame(0, ProductImage::query()->count());
        $this->assertGreaterThan(0, Product::query()->count());
        $this->assertGreaterThan(0, AffiliateLink::query()->count());
        $this->assertSame(0, $run->items_failed);

        $item = $run->items()->where('external_product_id', 'FAKE-WALLET-1')->firstOrFail();
        $this->assertSame(ImportRunItemStatus::Succeeded, $item->status);
        $this->assertStringContainsString('provider policy does not permit', (string) $item->error);
    }

    public function test_one_failed_image_does_not_remove_product_or_successful_image(): void
    {
        $successBody = (string) file_get_contents($this->rasterImagePath(640, 480, 'jpeg'));
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/images/wallet-1.jpg' => Http::response('missing', 404),
            'https://example.test/images/wallet-2.jpg' => Http::response($successBody, 200, ['Content-Type' => 'image/jpeg']),
            'https://example.test/images/coffee-kit.jpg' => Http::response($successBody, 200, ['Content-Type' => 'image/jpeg']),
            'https://example.test/images/missing-id.jpg' => Http::response($successBody, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $merchant = $this->fakeMerchant();
        $run = app(ImportCatalogAction::class)->execute($merchant);
        $wallet = $this->productByExternalId('FAKE-WALLET-1');
        $item = $run->items()->where('external_product_id', 'FAKE-WALLET-1')->firstOrFail();

        $this->assertCount(1, $wallet->images);
        $this->assertNotNull(AffiliateLink::query()->where('external_product_id', 'FAKE-WALLET-1')->first());
        $this->assertSame(ImportRunItemStatus::Succeeded, $item->status);
        $this->assertSame(0, $run->items_failed);
        $this->assertStringContainsString('wallet-1.jpg', (string) $item->error);
    }

    public function test_all_image_failures_keep_product_and_do_not_fail_the_item(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response('missing', 404),
        ]);

        $merchant = $this->fakeMerchant();
        $run = app(ImportCatalogAction::class)->execute($merchant);
        $item = $run->items()->where('external_product_id', 'FAKE-WALLET-1')->firstOrFail();

        $this->assertSame(0, ProductImage::query()->count());
        $this->assertSame(2, Product::query()->count());
        $this->assertSame(2, AffiliateLink::query()->count());
        $this->assertSame(ImportRunItemStatus::Succeeded, $item->status);
        $this->assertSame(0, $run->items_failed);
        $this->assertNotEmpty($item->error);
    }

    public function test_import_does_not_attach_taxonomies_and_discovery_routes_remain_14(): void
    {
        $this->fakeCatalogImageHttp();
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

        $discoveryRoutes = collect(Route::getRoutes())
            ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'));

        $this->assertCount(14, $discoveryRoutes);
    }

    private function fakeMerchant(): Merchant
    {
        return Merchant::query()->create([
            'name' => 'Fake Merchant',
            'slug' => 'fake-merchant',
            'affiliate_network' => 'fake',
        ]);
    }

    private function productByExternalId(string $externalProductId): Product
    {
        return Product::query()->whereHas('affiliateLinks', function ($query) use ($externalProductId): void {
            $query->where('external_product_id', $externalProductId);
        })->firstOrFail();
    }
}
