<?php

namespace Tests\Feature\CuratedCatalog;

use App\Actions\CuratedCatalog\AssembleCuratedProductIntakeBatchAction;
use App\Models\AffiliateLink;
use App\Models\CatalogProductSource;
use App\Models\CatalogSourceList;
use App\Models\CuratedProductIntakeRun;
use App\Models\Product;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\TestCase;

class CuratedProductIntakeDryRunCommandTest extends TestCase
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
        $this->directory = sys_get_temp_dir().'/curated-intake-dry-run-'.uniqid('', true);
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

    public function test_dry_run_writes_nothing_and_sends_no_http(): void
    {
        Http::fake();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0HUSBAND1']);
        $this->writeWishlist('boyfriend.json', 'Gifts for Boyfriend', ['B0HUSBAND1', 'B0BOYFRND1']);
        $this->writeWishlist('brother.json', 'Gifts for Brother', ['B0HUSBAND1']);

        $this->artisan('catalog:curated-intake', [
            'path' => $this->directory,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Unique merchant products')
            ->expectsOutputToContain('Dry run completed')
            ->assertSuccessful();

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, AffiliateLink::query()->count());
        $this->assertSame(0, CatalogSourceList::query()->count());
        $this->assertSame(0, CatalogProductSource::query()->count());
        $this->assertSame(0, CuratedProductIntakeRun::query()->count());
        Http::assertNothingSent();
    }

    public function test_dry_run_report_counts_merged_and_multi_list_products(): void
    {
        Http::fake();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0SHARED01', 'B0ONLYHUS1']);
        $this->writeWishlist('boyfriend.json', 'Gifts for Boyfriend', ['B0SHARED01']);
        $this->writeWishlist('q1.json', '01 - Gift Ideas - Q1 2026', ['B0SHARED01']);

        $this->artisan('catalog:curated-intake', [
            'path' => $this->directory,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Gifts for Husband')
            ->assertSuccessful();

        $report = app(AssembleCuratedProductIntakeBatchAction::class)
            ->execute($this->directory);

        $this->assertSame(3, $report->wishlistCount);
        $this->assertSame(4, $report->rawOccurrences);
        $this->assertSame(2, $report->uniqueProducts);
        $this->assertSame(2, $report->mergedOccurrences);
        $this->assertSame(1, $report->multiListProducts);
        $this->assertSame(2, $report->newProducts);
        $this->assertSame(0, $report->existingProducts);
        $this->assertSame(2, $report->recipientHintLists);
        $this->assertSame(1, $report->quarterlyArchiveLists);
        $this->assertSame(0, $report->unknownLists);
        $this->assertSame(1, $report->multiRecipientProducts);
        $this->assertSame(0, Relationship::query()->where('name', 'Unclassified')->count());
        Http::assertNothingSent();
    }

    public function test_dry_run_distinguishes_new_from_known_products(): void
    {
        Http::fake();
        $merchant = $this->configureCuratedAmazonMerchant();
        $product = Product::query()->create([
            'name' => 'Known Gift',
            'slug' => 'known-gift',
            'status' => 'draft',
            'price_amount' => '100.00',
            'price_currency' => 'INR',
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/B0KNOWN001?tag=test-tag-20',
            'external_product_id' => 'B0KNOWN001',
            'is_primary' => true,
            'status' => 'active',
        ]);

        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0KNOWN001', 'B0NEWITEM1']);

        $report = app(AssembleCuratedProductIntakeBatchAction::class)
            ->execute($this->directory);

        $this->assertSame(1, $report->newProducts);
        $this->assertSame(1, $report->existingProducts);
        $this->assertSame(1, Product::query()->count());
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
