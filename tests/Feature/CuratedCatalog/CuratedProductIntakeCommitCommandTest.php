<?php

namespace Tests\Feature\CuratedCatalog;

use App\Actions\CuratedCatalog\ProcessCuratedProductIntakeBatchAction;
use App\Actions\CuratedCatalog\ResolveCatalogSourceRelationshipHintsAction;
use App\CuratedCatalog\UnmappedCatalogSourceListsException;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogProductSource;
use App\Models\CatalogSourceList;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\Support\FakesCuratedProductImages;
use Tests\TestCase;

class CuratedProductIntakeCommitCommandTest extends TestCase
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
        $this->directory = sys_get_temp_dir().'/curated-intake-commit-'.uniqid('', true);
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

    public function test_deferred_commit_creates_one_product_and_all_provenance(): void
    {
        $this->fakeImageHttp();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0SHARED01']);
        $this->writeWishlist('boyfriend.json', 'Gifts for Boyfriend', ['B0SHARED01']);
        $this->writeWishlist('brother.json', 'Gifts for Brother', ['B0SHARED01']);
        $this->writeWishlist('q1.json', '01 - Gift Ideas - Q1 2026', ['B0SHARED01']);

        $this->artisan('catalog:curated-intake', [
            'path' => $this->directory,
            '--defer-classification' => true,
        ])->assertSuccessful();

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, AffiliateLink::query()->count());
        $this->assertSame(4, CatalogSourceList::query()->count());
        $this->assertSame(4, CatalogProductSource::query()->count());

        $product = Product::query()->first();
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertSame(0, $product->relationships()->count());
        $this->assertSame(0, $product->occasions()->count());
        $this->assertSame(0, $product->categories()->count());
        $this->assertSame('Gift B0SHARED01', $product->name);

        $hints = app(ResolveCatalogSourceRelationshipHintsAction::class)->execute($product);
        $this->assertCount(3, $hints);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'openai'));
    }

    public function test_bulk_commit_is_blocked_when_a_source_list_is_unmapped(): void
    {
        Http::fake();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0SHARED01']);
        $this->writeWishlist('mystery.json', 'Random Birthday Stuff', ['B0SHARED01']);

        $this->artisan('catalog:curated-intake', [
            'path' => $this->directory,
            '--defer-classification' => true,
        ])
            ->expectsOutputToContain('needs_source_mapping')
            ->expectsOutputToContain('Random Birthday Stuff')
            ->assertFailed();

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, CatalogSourceList::query()->count());
        $this->assertSame(0, CatalogProductSource::query()->count());
        Http::assertNothingSent();
    }

    public function test_allow_unknown_source_lists_override_persists_unknown_kind(): void
    {
        $this->fakeImageHttp();
        $this->writeWishlist('mystery.json', 'Random Birthday Stuff', ['B0MYSTERY1']);

        $this->artisan('catalog:curated-intake', [
            'path' => $this->directory,
            '--defer-classification' => true,
            '--allow-unknown-source-lists' => true,
        ])->assertSuccessful();

        $list = CatalogSourceList::query()->first();
        $this->assertNotNull($list);
        $this->assertSame('unknown', $list->kind->value);
        $this->assertNull($list->relationship_id);
        $this->assertSame(1, Product::query()->count());
    }

    public function test_reimport_is_idempotent_and_preserves_first_seen(): void
    {
        $this->fakeImageHttp();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0SHARED01']);
        $this->writeWishlist('boyfriend.json', 'Gifts for Boyfriend', ['B0SHARED01']);

        app(ProcessCuratedProductIntakeBatchAction::class)->commit($this->directory, deferClassification: true);

        $firstSeen = CatalogProductSource::query()->orderBy('id')->pluck('first_seen_at', 'catalog_source_list_id');
        $this->travel(1)->hours();
        $this->fakeImageHttp();

        app(ProcessCuratedProductIntakeBatchAction::class)->commit($this->directory, deferClassification: true);

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(2, CatalogSourceList::query()->count());
        $this->assertSame(2, CatalogProductSource::query()->count());

        foreach (CatalogProductSource::query()->get() as $source) {
            $this->assertTrue($source->first_seen_at->equalTo($firstSeen[$source->catalog_source_list_id]));
            $this->assertTrue($source->last_seen_at->greaterThan($source->first_seen_at));
            $this->assertSame(2, $source->occurrence_count);
        }
    }

    public function test_unmapped_exception_contains_list_details(): void
    {
        Http::fake();
        $this->writeWishlist('mystery.json', 'Random Birthday Stuff', ['B0MYSTERY1']);

        try {
            app(ProcessCuratedProductIntakeBatchAction::class)->commit($this->directory, deferClassification: true);
            $this->fail('Expected unmapped source lists to block commit.');
        } catch (UnmappedCatalogSourceListsException $exception) {
            $this->assertSame('Random Birthday Stuff', $exception->unmappedLists[0]['name']);
            $this->assertSame('needs_source_mapping', $exception->unmappedLists[0]['status']);
        }

        Http::assertNothingSent();
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
     */
    private function writeWishlist(string $filename, string $listName, array $asins): void
    {
        $items = array_map(fn (string $asin): array => $this->curatedWishlistItem($asin), $asins);

        file_put_contents(
            $this->directory.DIRECTORY_SEPARATOR.$filename,
            $this->curatedWishlistPayload($listName, $items),
        );
    }
}
