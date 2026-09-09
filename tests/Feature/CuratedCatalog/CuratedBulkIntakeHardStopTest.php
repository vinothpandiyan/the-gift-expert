<?php

namespace Tests\Feature\CuratedCatalog;

use App\Models\AffiliateLink;
use App\Models\CatalogProductSource;
use App\Models\CatalogSourceList;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\TestCase;

class CuratedBulkIntakeHardStopTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureCuratedAmazonMerchant();
        $this->seedCuratedRelationships();
        $this->directory = sys_get_temp_dir().'/curated-intake-hard-stop-'.uniqid('', true);
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

    public function test_duplicate_logical_wishlist_identity_blocks_commit(): void
    {
        Http::fake();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0HUSBAND1']);
        $this->writeWishlist('husband-copy.json', 'Gifts for Husband', ['B0HUSBAND2']);

        $this->artisan('catalog:curated-intake', [
            'path' => $this->directory,
            '--defer-classification' => true,
        ])
            ->expectsOutputToContain('duplicate_logical_wishlist_identity')
            ->assertFailed();

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, CatalogSourceList::query()->count());
        Http::assertNothingSent();
    }

    public function test_malformed_source_list_identity_blocks_commit(): void
    {
        Http::fake();
        file_put_contents(
            $this->directory.DIRECTORY_SEPARATOR.'bad.json',
            json_encode([
                'version' => 2,
                'merchant' => 'amazon-in',
                'captured_at' => '2026-09-08T10:00:00+05:30',
                'context' => [
                    'source_list_name' => ['not', 'a', 'string'],
                ],
                'items' => [
                    $this->curatedWishlistItem('B0BADLIST1'),
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $this->artisan('catalog:curated-intake', [
            'path' => $this->directory,
            '--defer-classification' => true,
        ])
            ->expectsOutputToContain('malformed_source_list_identity')
            ->assertFailed();

        $this->assertSame(0, Product::query()->count());
        Http::assertNothingSent();
    }

    public function test_directory_file_without_source_list_blocks_commit(): void
    {
        Http::fake();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0HUSBAND1']);
        file_put_contents(
            $this->directory.DIRECTORY_SEPARATOR.'legacy.json',
            $this->curatedPayload(),
        );

        $this->artisan('catalog:curated-intake', [
            'path' => $this->directory,
            '--defer-classification' => true,
        ])
            ->expectsOutputToContain('missing_source_list_identity')
            ->assertFailed();

        $this->assertSame(0, Product::query()->count());
        Http::assertNothingSent();
    }

    public function test_malformed_json_file_is_reported_by_filename(): void
    {
        Http::fake();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0HUSBAND1']);
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'broken.json', '{not-json');

        $this->artisan('catalog:curated-intake', [
            'path' => $this->directory,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('broken.json')
            ->assertFailed();

        $this->assertSame(0, Product::query()->count());
        Http::assertNothingSent();
    }

    public function test_significant_missing_asins_block_commit(): void
    {
        Http::fake();
        $items = [];

        for ($i = 0; $i < 6; $i++) {
            $items[] = [
                'external_product_id' => 'NOTASIN'.$i,
                'source_url' => 'https://www.amazon.in/dp/B0VALID00'.$i,
                'title' => 'Broken '.$i,
            ];
        }

        $items[] = $this->curatedWishlistItem('B0VALID001');

        file_put_contents(
            $this->directory.DIRECTORY_SEPARATOR.'husband.json',
            $this->curatedWishlistPayload('Gifts for Husband', $items),
        );

        $this->artisan('catalog:curated-intake', [
            'path' => $this->directory,
            '--defer-classification' => true,
        ])
            ->expectsOutputToContain('significant_missing_asins')
            ->assertFailed();

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, CatalogProductSource::query()->count());
        $this->assertSame(0, AffiliateLink::query()->count());
        Http::assertNothingSent();
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
