<?php

namespace Tests\Feature\CuratedCatalog;

use App\Actions\CuratedCatalog\BuildCuratedRelationshipHintFingerprintAction;
use App\Actions\CuratedCatalog\BuildCuratedTaxonomyContentFingerprintAction;
use App\Actions\CuratedCatalog\PlanCuratedMerchantProductClassificationAction;
use App\Actions\CuratedCatalog\ProcessCuratedProductIntakeBatchAction;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Category;
use App\Models\CuratedProductIntakeRun;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\Support\FakesCommercialEnrichment;
use Tests\Support\FakesCuratedProductImages;
use Tests\TestCase;

class ClassifyCuratedMerchantProductsCommandTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use FakesCommercialEnrichment;
    use FakesCuratedProductImages;
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureCuratedAmazonMerchant();
        $this->seedCuratedRelationships();
        $this->directory = sys_get_temp_dir().'/curated-classify-'.uniqid('', true);
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

    public function test_deferred_products_are_classified_once_from_all_hints(): void
    {
        $home = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        $this->fakeImageHttp();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0SHARED01']);
        $this->writeWishlist('boyfriend.json', 'Gifts for Boyfriend', ['B0SHARED01']);
        $this->writeWishlist('brother.json', 'Gifts for Brother', ['B0SHARED01']);
        $this->writeWishlist('q1.json', '01 - Gift Ideas - Q1 2026', ['B0SHARED01']);
        $this->writeWishlist('inbox.json', '00 - Unclassified Gift Ideas', ['B0SHARED01']);

        app(ProcessCuratedProductIntakeBatchAction::class)->commit($this->directory, deferClassification: true);

        $product = Product::query()->firstOrFail();
        $this->assertSame(TaxonomyClassificationStatus::None, $product->taxonomy_classification_status);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                    ],
                ]),
            ),
        ]);

        $this->artisan('catalog:classify-curated', ['--status' => 'none'])
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }

            $user = $request['messages'][1]['content'] ?? '';

            return str_contains((string) $user, 'Husband')
                && str_contains((string) $user, 'Boyfriend')
                && str_contains((string) $user, 'Brother');
        });

        $product->refresh();
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertTrue($product->categories()->where('categories.id', $home->id)->wherePivot('is_primary', true)->exists());
        $this->assertTrue($product->relationships()->where('slug', 'husband')->exists());
    }

    public function test_dry_run_does_not_call_ai(): void
    {
        Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        $this->fakeImageHttp();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0SHARED01']);
        app(ProcessCuratedProductIntakeBatchAction::class)->commit($this->directory, deferClassification: true);

        Http::fake();

        $this->artisan('catalog:classify-curated', [
            '--status' => 'none',
            '--dry-run' => true,
        ])->expectsOutputToContain('Dry run completed')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(TaxonomyClassificationStatus::None, Product::query()->first()->taxonomy_classification_status);
    }

    public function test_retry_failed_is_scoped_to_intake_run(): void
    {
        Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        $this->fakeImageHttp();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0SHARED01']);
        app(ProcessCuratedProductIntakeBatchAction::class)->commit($this->directory, deferClassification: true);

        $product = Product::query()->firstOrFail();
        $product->taxonomy_classification_status = TaxonomyClassificationStatus::Failed;
        $product->taxonomy_classification_version = (int) config('curated_catalog.taxonomy_classification.version', 1);
        $product->save();

        $other = Product::query()->create([
            'name' => 'Other Failed',
            'slug' => 'other-failed',
            'status' => ProductStatus::Draft->value,
            'price_amount' => '10.00',
            'price_currency' => 'INR',
        ]);
        $other->taxonomy_classification_status = TaxonomyClassificationStatus::Failed;
        $other->taxonomy_classification_version = (int) config('curated_catalog.taxonomy_classification.version', 1);
        $other->save();

        $run = CuratedProductIntakeRun::query()->firstOrFail();
        Http::fake();

        $this->artisan('catalog:classify-curated', [
            '--intake-run' => (string) $run->id,
            '--status' => 'failed',
            '--retry-failed' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Failed that would retry')
            ->expectsOutputToContain(sprintf(
                'product=%d status=failed version=%d reason=retry_failed',
                $product->id,
                (int) config('curated_catalog.taxonomy_classification.version', 1),
            ))
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(TaxonomyClassificationStatus::Failed, $other->fresh()->taxonomy_classification_status);
    }

    public function test_product_retry_failed_at_current_version_is_eligible(): void
    {
        Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        $this->fakeImageHttp();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0SHARED01']);
        app(ProcessCuratedProductIntakeBatchAction::class)->commit($this->directory, deferClassification: true);

        $product = Product::query()->firstOrFail();
        $version = (int) config('curated_catalog.taxonomy_classification.version', 1);
        $product->taxonomy_classification_status = TaxonomyClassificationStatus::Failed;
        $product->taxonomy_classification_version = $version;
        $product->save();

        Http::fake();

        $plan = app(PlanCuratedMerchantProductClassificationAction::class)->execute(
            null,
            $product->id,
            null,
            null,
            null,
            false,
            true,
        );

        $this->assertSame(1, $plan->totalConsidered);
        $this->assertSame(1, $plan->eligible);
        $this->assertSame(0, $plan->skippedCurrent);
        $this->assertSame('retry_failed', $plan->items[0]->decisionReason);
        $this->assertSame('failed', $plan->items[0]->status);
        $this->assertSame($version, $plan->items[0]->version);

        $this->artisan('catalog:classify-curated', [
            '--product' => (string) $product->id,
            '--retry-failed' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain(sprintf(
                'product=%d status=failed version=%d reason=retry_failed eligible=yes',
                $product->id,
                $version,
            ))
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_product_retry_failed_does_not_reclassify_current_ai_accepted(): void
    {
        $home = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        $this->fakeImageHttp();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0SHARED01']);
        app(ProcessCuratedProductIntakeBatchAction::class)->commit($this->directory, deferClassification: true);

        $product = Product::query()->firstOrFail();
        $version = (int) config('curated_catalog.taxonomy_classification.version', 1);
        $product->taxonomy_classification_status = TaxonomyClassificationStatus::AiAccepted;
        $product->taxonomy_classification_version = $version;
        $product->taxonomy_content_fingerprint = app(BuildCuratedTaxonomyContentFingerprintAction::class)->execute($product);
        $product->taxonomy_relationship_hint_fingerprint = app(BuildCuratedRelationshipHintFingerprintAction::class)->execute($product);
        $product->save();
        $product->categories()->attach($home->id, ['is_primary' => true]);

        Http::fake();

        $plan = app(PlanCuratedMerchantProductClassificationAction::class)->execute(
            null,
            $product->id,
            null,
            null,
            null,
            false,
            true,
        );

        $this->assertSame(1, $plan->totalConsidered);
        $this->assertSame(0, $plan->eligible);
        $this->assertSame(1, $plan->skippedCurrent);
        $this->assertSame('current', $plan->items[0]->decisionReason);
        $this->assertSame('ai_accepted', $plan->items[0]->status);

        $this->artisan('catalog:classify-curated', [
            '--product' => (string) $product->id,
            '--retry-failed' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain(sprintf(
                'product=%d status=ai_accepted version=%d reason=current eligible=no',
                $product->id,
                $version,
            ))
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->fresh()->taxonomy_classification_status);
        $this->assertTrue($product->fresh()->categories()->where('categories.id', $home->id)->exists());
    }

    public function test_product_force_does_not_default_to_unclassified_status(): void
    {
        Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        $this->fakeImageHttp();
        $this->writeWishlist('husband.json', 'Gifts for Husband', ['B0SHARED01']);
        app(ProcessCuratedProductIntakeBatchAction::class)->commit($this->directory, deferClassification: true);

        $product = Product::query()->firstOrFail();
        $product->taxonomy_classification_status = TaxonomyClassificationStatus::Review;
        $product->save();

        Http::fake();

        $plan = app(PlanCuratedMerchantProductClassificationAction::class)->execute(
            null,
            $product->id,
            null,
            null,
            null,
            true,
            true,
        );

        $this->assertSame(1, $plan->eligible);
        $this->assertSame('forced', $plan->items[0]->decisionReason);
        $this->assertSame('review', $plan->items[0]->status);

        $this->artisan('catalog:classify-curated', [
            '--product' => (string) $product->id,
            '--force' => true,
            '--dry-run' => true,
        ])->assertSuccessful();

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
        $items = array_map(
            fn (string $asin): array => $this->curatedWishlistItem($asin),
            $asins,
        );

        file_put_contents(
            $this->directory.DIRECTORY_SEPARATOR.$filename,
            $this->curatedWishlistPayload($listName, $items),
        );
    }
}
