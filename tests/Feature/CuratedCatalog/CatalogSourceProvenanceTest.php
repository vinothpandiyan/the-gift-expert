<?php

namespace Tests\Feature\CuratedCatalog;

use App\Actions\CuratedCatalog\ProcessCuratedProductIntakeBatchAction;
use App\Actions\CuratedCatalog\ResolveCatalogSourceRelationshipHintsAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\CatalogSourceListKind;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogProductSource;
use App\Models\CatalogSourceList;
use App\Models\Category;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\Support\FakesCuratedProductImages;
use Tests\TestCase;

class CatalogSourceProvenanceTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use FakesCuratedProductImages;
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureCuratedAmazonMerchant();
        $this->seedCuratedRelationships();
        $this->directory = sys_get_temp_dir().'/curated-intake-prov-'.uniqid('', true);
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_quarterly_archive_adds_provenance_without_taxonomy_or_classification(): void
    {
        $merchant = $this->configureCuratedAmazonMerchant();
        $home = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        $birthday = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);
        $husband = Relationship::query()->where('slug', 'husband')->firstOrFail();
        $product = Product::query()->create([
            'name' => 'Editorial Name',
            'slug' => 'editorial-name',
            'short_description' => 'Short',
            'description' => 'Long',
            'status' => ProductStatus::Draft,
            'price_amount' => '100.00',
            'price_currency' => 'INR',
        ]);
        $product->categories()->attach($home->id, ['is_primary' => true]);
        $product->occasions()->attach($birthday->id);
        $product->relationships()->attach($husband->id);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH?tag=old',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

        $this->fakeImageHttp();
        $this->writeWishlist('q1.json', '01 - Gift Ideas - Q1 2026', ['B0ABCDEFGH'], [
            'title' => 'Should not overwrite editorial',
            'price_amount' => '1499.00',
        ]);

        Http::fake([
            'https://m.media-amazon.com/images/I/*' => Http::response(
                (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg')),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        app(ProcessCuratedProductIntakeBatchAction::class)->commit($this->directory, deferClassification: true);

        $product->refresh();
        $this->assertSame('Editorial Name', $product->name);
        $this->assertSame('1499.00', $product->price_amount);
        $this->assertTrue($product->relationships()->where('relationships.id', $husband->id)->exists());
        $this->assertTrue($product->occasions()->where('occasions.id', $birthday->id)->exists());
        $this->assertSame(1, CatalogSourceList::query()->where('kind', CatalogSourceListKind::QuarterlyArchive)->count());
        $this->assertSame(1, CatalogProductSource::query()->count());
        $this->assertSame([], app(ResolveCatalogSourceRelationshipHintsAction::class)->execute($product));
        $this->assertSame(0, Relationship::query()->where('name', 'like', '%Q1%')->count());
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'openai'));
    }

    public function test_unclassified_inbox_does_not_create_taxonomy(): void
    {
        $this->fakeImageHttp();
        $this->writeWishlist('inbox.json', '00 - Unclassified Gift Ideas', ['B0INBOX001']);

        app(ProcessCuratedProductIntakeBatchAction::class)->commit($this->directory, deferClassification: true);

        $list = CatalogSourceList::query()->first();
        $product = Product::query()->first();

        $this->assertSame(CatalogSourceListKind::UnclassifiedInbox, $list->kind);
        $this->assertNull($list->relationship_id);
        $this->assertSame(0, $product->relationships()->count());
        $this->assertSame(0, Relationship::query()->where('name', 'Unclassified')->count());
        $this->assertSame([], app(ResolveCatalogSourceRelationshipHintsAction::class)->execute($product));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'openai'));
    }

    public function test_known_product_refresh_upserts_provenance_without_ai_or_taxonomy_overwrite(): void
    {
        $merchant = $this->configureCuratedAmazonMerchant();
        $home = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        $husband = Relationship::query()->where('slug', 'husband')->firstOrFail();
        $product = Product::query()->create([
            'name' => 'Editorial Name',
            'slug' => 'editorial-known',
            'short_description' => 'Short',
            'description' => 'Long',
            'status' => ProductStatus::Published,
            'price_amount' => '100.00',
            'price_currency' => 'INR',
        ]);
        $product->categories()->attach($home->id, ['is_primary' => true]);
        $product->relationships()->attach($husband->id);
        $link = AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH?tag=old',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

        $this->fakeImageHttp();
        $this->writeWishlist('boyfriend.json', 'Gifts for Boyfriend', ['B0ABCDEFGH'], [
            'price_amount' => '2499.00',
        ]);

        app(ProcessCuratedProductIntakeBatchAction::class)->commit($this->directory, deferClassification: true);

        $product->refresh();
        $link->refresh();

        $this->assertSame('Editorial Name', $product->name);
        $this->assertSame('Short', $product->short_description);
        $this->assertSame('2499.00', $product->price_amount);
        $this->assertTrue($product->relationships()->where('relationships.id', $husband->id)->exists());
        $this->assertSame(1, $product->relationships()->count());
        $this->assertNotNull($link->last_seen_at);
        $this->assertSame('in_stock', $link->availability);
        $this->assertSame(1, CatalogProductSource::query()->count());

        $hints = app(ResolveCatalogSourceRelationshipHintsAction::class)->execute($link);
        $boyfriend = Relationship::query()->where('slug', 'boyfriend')->firstOrFail();
        $this->assertSame([$boyfriend->id], $hints);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'openai'));
    }

    public function test_relationship_hints_exclude_inbox_and_archive(): void
    {
        $this->fakeImageHttp();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0SHARED01']);
        $this->writeWishlist('inbox.json', '00 - Unclassified Gift Ideas', ['B0SHARED01']);
        $this->writeWishlist('q1.json', '01 - Gift Ideas - Q1 2026', ['B0SHARED01']);

        app(ProcessCuratedProductIntakeBatchAction::class)->commit($this->directory, deferClassification: true);

        $product = Product::query()->first();
        $hints = app(ResolveCatalogSourceRelationshipHintsAction::class)->execute($product);
        $husband = Relationship::query()->where('slug', 'husband')->firstOrFail();

        $this->assertSame([$husband->id], $hints);
        $this->assertSame(3, CatalogProductSource::query()->count());
    }

    private function fakeImageHttp(): void
    {
        Storage::fake('public');
        $body = (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg'));

        Http::fake([
            'https://m.media-amazon.com/images/I/*' => Http::response($body, 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    /**
     * @param  list<string>  $asins
     * @param  array<string, mixed>  $itemOverrides
     */
    private function writeWishlist(string $filename, string $listName, array $asins, array $itemOverrides = []): void
    {
        $items = array_map(
            fn (string $asin): array => $this->curatedWishlistItem($asin, $itemOverrides),
            $asins,
        );

        file_put_contents(
            $this->directory.DIRECTORY_SEPARATOR.$filename,
            $this->curatedWishlistPayload($listName, $items),
        );
    }
}
