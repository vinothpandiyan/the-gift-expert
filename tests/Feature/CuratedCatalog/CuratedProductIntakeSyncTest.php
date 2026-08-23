<?php

namespace Tests\Feature\CuratedCatalog;

use App\Actions\CuratedCatalog\CreateCuratedMerchantProductAction;
use App\Actions\CuratedCatalog\PreviewCuratedProductIntakeAction;
use App\Actions\CuratedCatalog\ProcessCuratedProductIntakeAction;
use App\CuratedCatalog\CuratedImageAcquisitionOutcome;
use App\Enums\AffiliateLinkStatus;
use App\Enums\CuratedProductIntakeItemOutcome;
use App\Enums\CuratedProductIntakeRunStatus;
use App\Enums\ProductStatus;
use App\Filament\Pages\CuratedProductIntake;
use App\Jobs\ProcessCuratedProductIntakeRunJob;
use App\Models\AffiliateLink;
use App\Models\Category;
use App\Models\CuratedProductIntakeItem;
use App\Models\CuratedProductIntakeRun;
use App\Models\Merchant;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\Support\FakesCommercialEnrichment;
use Tests\Support\FakesCuratedProductImages;
use Tests\Support\ProcessesCuratedSyncRuns;
use Tests\TestCase;

class CuratedProductIntakeSyncTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use FakesCommercialEnrichment;
    use FakesCuratedProductImages;
    use ProcessesCuratedSyncRuns;
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->merchant = $this->configureCuratedAmazonMerchant();
        $this->seedTaxonomy();
    }

    public function test_fifty_three_item_payload_processes_all_items_in_one_run(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = $this->buildItems(53);
        $payload = $this->curatedPayload(['items' => $items]);

        $this->fakeEnrichmentSequence($home->id, 53);

        Queue::fake();

        $started = app(ProcessCuratedProductIntakeAction::class)->start($payload);
        Queue::assertPushed(ProcessCuratedProductIntakeRunJob::class, 1);

        app(ProcessCuratedProductIntakeAction::class)->processRun($started->runId, $payload);
        $result = app(ProcessCuratedProductIntakeAction::class)->resultFromRun(
            CuratedProductIntakeRun::query()->findOrFail($started->runId),
        );

        $this->assertSame(53, $result->itemsCreated);
        $this->assertSame(53, Product::query()->count());
        $this->assertSame(53, CuratedProductIntakeItem::query()->count());
        $this->assertSame(0, CuratedProductIntakeItem::query()->where('error', 'not_processed_this_commit')->count());
        $this->assertFalse(collect($result->processedItems)->pluck('warnings')->flatten()->contains('not_processed_this_commit'));
    }

    public function test_mixed_payload_creates_and_updates_in_one_run(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $existingItems = $this->buildItems(20, 2001);
        $this->fakeEnrichmentSequence($home->id, 20);
        $this->runCuratedSync($this->curatedPayload(['items' => $existingItems]));

        Http::fake();
        $newItems = $this->buildItems(33, 3001);
        $this->fakeEnrichmentSequence($home->id, 33);
        $payload = $this->curatedPayload(['items' => array_merge($existingItems, $newItems)]);

        $result = $this->runCuratedSync($payload);

        $this->assertSame(33, $result->itemsCreated);
        $this->assertSame(20, $result->itemsUpdated);
        $this->assertSame(53, Product::query()->count());
    }

    public function test_re_sync_same_payload_produces_updates_without_duplicates(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = $this->buildItems(53);
        $payload = $this->curatedPayload(['items' => $items]);

        $this->fakeEnrichmentSequence($home->id, 53);
        $this->runCuratedSync($payload);

        Http::fake();
        $result = $this->runCuratedSync($payload);

        $this->assertSame(0, $result->itemsCreated);
        $this->assertSame(53, $result->itemsUpdated);
        $this->assertSame(53, Product::query()->count());
        $this->assertSame(53, AffiliateLink::query()->count());
    }

    public function test_existing_product_with_primary_image_does_not_download_again(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = $this->buildItems(1, 1001);
        $payload = $this->curatedPayload(['items' => $items]);

        $this->fakeEnrichmentSequence($home->id, 1);
        $this->runCuratedSync($payload);

        Http::fake();

        $result = $this->runCuratedSync($payload);

        $this->assertSame(1, $result->itemsUpdated);
        $this->assertContains(CuratedImageAcquisitionOutcome::STATUS_ALREADY_PRESENT, $result->processedItems[0]['warnings']);
        Http::assertNothingSent();
    }

    public function test_existing_product_without_image_backfills_automatically(): void
    {
        $product = Product::query()->create([
            'name' => 'Gift without image',
            'slug' => 'gift-without-image',
            'status' => ProductStatus::Draft,
            'price_amount' => '499.00',
            'price_currency' => 'INR',
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $this->merchant->id,
            'url' => 'https://www.amazon.in/dp/B0NOIMAGE1?tag=old',
            'external_product_id' => 'B0NOIMAGE1',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

        $imageUrl = $this->curatedImageUrlForAsin('B0NOIMAGE1');
        $payload = $this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0NOIMAGE1',
                'source_url' => 'https://www.amazon.in/dp/B0NOIMAGE1',
                'title' => 'Gift without image',
                'price_amount' => '499.00',
                'price_currency' => 'INR',
                'source_image_url' => $imageUrl,
            ]],
        ]);

        Http::fake([
            $imageUrl => Http::response(
                (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg')),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);
        Storage::fake('public');

        $result = $this->runCuratedSync($payload);

        $this->assertSame(1, $result->itemsUpdated);
        $this->assertSame(1, ProductImage::query()->count());
        $this->assertTrue($product->fresh()->images()->where('is_primary', true)->exists());
        $this->assertContains(CuratedImageAcquisitionOutcome::STATUS_ACQUIRED, $result->processedItems[0]['warnings']);
    }

    public function test_new_product_with_valid_image_creates_primary_product_image(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = $this->buildItems(1, 801);
        $payload = $this->curatedPayload(['items' => $items]);

        $this->fakeEnrichmentSequence($home->id, 1);

        $result = $this->runCuratedSync($payload);

        $product = Product::query()->firstOrFail();
        $image = $product->images()->first();

        $this->assertSame(1, $result->itemsCreated);
        $this->assertNotNull($image);
        $this->assertTrue($image->is_primary);
        $this->assertMatchesRegularExpression(
            '#^products/'.$product->id.'/images/[0-9a-f-]{36}\.webp$#',
            $image->path,
        );
    }

    public function test_broken_image_leaves_product_draft_and_records_warning(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = $this->buildItems(1, 701);
        $payload = $this->curatedPayload(['items' => $items]);
        $imageUrl = $this->curatedImageUrlForAsin($items[0]['external_product_id']);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => ['primary_category_id' => $home->id, 'category_ids' => [$home->id]],
                ]),
            ),
            $imageUrl => Http::response('<html>error</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->runCuratedSync($payload);

        $this->assertSame(1, $result->itemsCreated);
        $this->assertSame(ProductStatus::Draft, Product::query()->first()->status);
        $this->assertSame(0, ProductImage::query()->count());
        $this->assertContains(CuratedImageAcquisitionOutcome::STATUS_FAILED, $result->processedItems[0]['warnings']);
    }

    public function test_duplicate_asin_in_payload_skips_second_item(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = [
            $this->buildItem(901),
            array_merge($this->buildItem(901), ['title' => 'Duplicate gift']),
        ];
        $payload = $this->curatedPayload(['items' => $items]);

        $this->fakeEnrichmentSequence($home->id, 1);

        $result = $this->runCuratedSync($payload);

        $this->assertSame(1, $result->itemsCreated);
        $this->assertSame(1, $result->itemsSkipped);
        $this->assertSame(1, Product::query()->count());
    }

    public function test_one_ai_failure_does_not_stop_remaining_items(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = $this->buildItems(3, 501);
        $payload = $this->curatedPayload(['items' => $items]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push($this->commercialEnrichmentCompletion([
                    'taxonomy' => ['primary_category_id' => $home->id, 'category_ids' => [$home->id]],
                ]))
                ->pushStatus(500)
                ->push($this->commercialEnrichmentCompletion([
                    'taxonomy' => ['primary_category_id' => $home->id, 'category_ids' => [$home->id]],
                ])),
            'https://m.media-amazon.com/images/I/*' => Http::response(
                (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg')),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);
        Storage::fake('public');

        $result = $this->runCuratedSync($payload);

        $this->assertSame(2, $result->itemsCreated);
        $this->assertSame(1, $result->itemsFailed);
        $this->assertSame(2, Product::query()->count());
    }

    public function test_one_image_failure_does_not_stop_remaining_items(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = $this->buildItems(2, 601);
        $payload = $this->curatedPayload(['items' => $items]);

        $firstAsin = $items[0]['external_product_id'];
        $secondAsin = $items[1]['external_product_id'];
        $goodUrl = $this->curatedImageUrlForAsin($firstAsin);
        $badUrl = $this->curatedImageUrlForAsin($secondAsin);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push($this->commercialEnrichmentCompletion([
                    'taxonomy' => ['primary_category_id' => $home->id, 'category_ids' => [$home->id]],
                ]))
                ->push($this->commercialEnrichmentCompletion([
                    'taxonomy' => ['primary_category_id' => $home->id, 'category_ids' => [$home->id]],
                ])),
            $goodUrl => Http::response(
                (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg')),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
            $badUrl => Http::response('not-an-image', 200, ['Content-Type' => 'text/plain']),
        ]);
        Storage::fake('public');

        $result = $this->runCuratedSync($payload);

        $this->assertSame(2, $result->itemsCreated);
        $this->assertSame(2, Product::query()->count());
        $this->assertSame(1, ProductImage::query()->count());
        $this->assertContains(CuratedImageAcquisitionOutcome::STATUS_FAILED, $result->processedItems[1]['warnings']);
    }

    public function test_refresh_preserves_price_when_incoming_price_missing(): void
    {
        $product = Product::query()->create([
            'name' => 'Editorial Name',
            'slug' => 'editorial-name-sync',
            'status' => ProductStatus::Draft,
            'price_amount' => '100.00',
            'price_currency' => 'INR',
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $this->merchant->id,
            'url' => 'https://www.amazon.in/dp/B0PRICE01?tag=old',
            'external_product_id' => 'B0PRICE001',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

        Http::fake();
        $payload = $this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0PRICE001',
                'source_url' => 'https://www.amazon.in/dp/B0PRICE001',
                'title' => 'Updated title from browser',
            ]],
        ]);

        $this->runCuratedSync($payload);

        $this->assertSame('100.00', $product->fresh()->price_amount);
        $this->assertSame('Editorial Name', $product->fresh()->name);
    }

    public function test_wishlist_absence_leaves_existing_product_untouched(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = $this->buildItems(1, 101);
        $payload = $this->curatedPayload(['items' => $items]);

        $this->fakeEnrichmentSequence($home->id, 1);
        $this->runCuratedSync($payload);

        $product = Product::query()->firstOrFail();
        $originalPrice = $product->price_amount;

        Http::fake();
        $this->fakeEnrichmentSequence($home->id, 1);
        $otherPayload = $this->curatedPayload(['items' => $this->buildItems(1, 202)]);
        $this->runCuratedSync($otherPayload);

        $this->assertTrue(Product::query()->whereKey($product->id)->exists());
        $this->assertSame($originalPrice, $product->fresh()->price_amount);
        $this->assertSame(ProductStatus::Draft, $product->fresh()->status);
        $this->assertSame(2, Product::query()->count());
    }

    public function test_ssrf_image_url_is_rejected_before_http(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = [[
            'external_product_id' => 'B0SSRF0001',
            'source_url' => 'https://www.amazon.in/dp/B0SSRF0001',
            'title' => 'SSRF test gift',
            'price_amount' => '499.00',
            'price_currency' => 'INR',
            'source_image_url' => 'https://127.0.0.1/private.jpg',
        ]];
        $payload = $this->curatedPayload(['items' => $items]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => ['primary_category_id' => $home->id, 'category_ids' => [$home->id]],
                ]),
            ),
        ]);

        $result = $this->runCuratedSync($payload);

        $this->assertSame(1, $result->itemsCreated);
        $this->assertContains(CuratedImageAcquisitionOutcome::STATUS_FAILED, $result->processedItems[0]['warnings']);
        Http::assertSentCount(1);
    }

    public function test_repeated_sync_does_not_duplicate_product_images(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = $this->buildItems(1, 301);
        $payload = $this->curatedPayload(['items' => $items]);
        $asin = $items[0]['external_product_id'];
        $imageUrl = $this->curatedImageUrlForAsin($asin);

        $this->fakeEnrichmentSequence($home->id, 1);
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => ['primary_category_id' => $home->id, 'category_ids' => [$home->id]],
                ]),
            ),
            $imageUrl => Http::response(
                (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg')),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);
        Storage::fake('public');
        $this->runCuratedSync($payload);

        $this->assertSame(1, ProductImage::query()->count());

        Http::fake();
        $result = $this->runCuratedSync($payload);

        $this->assertSame(1, $result->itemsUpdated);
        $this->assertSame(1, ProductImage::query()->count());
        $this->assertContains(CuratedImageAcquisitionOutcome::STATUS_ALREADY_PRESENT, $result->processedItems[0]['warnings']);
        Http::assertNothingSent();
    }

    public function test_confirm_dispatches_job_without_inline_processing(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => ['primary_category_id' => $home->id, 'category_ids' => [$home->id]],
                ]),
            ),
        ]);

        Queue::fake();
        $this->actingAs(User::factory()->create());

        Livewire::test(CuratedProductIntake::class)
            ->set('data.merchant_slug', 'amazon-in')
            ->set('data.payload', $this->curatedPayload())
            ->call('confirmSync')
            ->assertSet('activeRunId', fn ($id): bool => is_int($id) && $id > 0);

        Queue::assertPushed(ProcessCuratedProductIntakeRunJob::class, 1);
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(1, CuratedProductIntakeRun::query()->count());
        $this->assertSame(CuratedProductIntakeRunStatus::Processing, CuratedProductIntakeRun::query()->first()->status);
    }

    public function test_job_processes_entire_run(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = $this->buildItems(5);
        $payload = $this->curatedPayload(['items' => $items]);

        $this->fakeEnrichmentSequence($home->id, 5);
        Queue::fake();

        $started = app(ProcessCuratedProductIntakeAction::class)->start($payload);
        app(ProcessCuratedProductIntakeRunJob::class, [
            'runId' => $started->runId,
            'json' => $payload,
        ])->handle(app(ProcessCuratedProductIntakeAction::class));

        $run = CuratedProductIntakeRun::query()->findOrFail($started->runId);

        $this->assertSame(CuratedProductIntakeRunStatus::Completed, $run->status);
        $this->assertSame(5, $run->items_created);
        $this->assertSame(5, CuratedProductIntakeItem::query()->count());
    }

    public function test_job_retry_after_partial_product_creation_remains_idempotent(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = $this->buildItems(2, 401);
        $payload = $this->curatedPayload(['items' => $items]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push($this->commercialEnrichmentCompletion([
                    'taxonomy' => ['primary_category_id' => $home->id, 'category_ids' => [$home->id]],
                ]))
                ->push($this->commercialEnrichmentCompletion([
                    'taxonomy' => ['primary_category_id' => $home->id, 'category_ids' => [$home->id]],
                ])),
            'https://m.media-amazon.com/images/I/*' => Http::response(
                (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg')),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);
        Storage::fake('public');

        Queue::fake();

        $process = app(ProcessCuratedProductIntakeAction::class);
        $preview = app(PreviewCuratedProductIntakeAction::class)->execute($payload);
        $started = $process->start($payload);
        $run = CuratedProductIntakeRun::query()->findOrFail($started->runId);

        $firstItem = $preview->items[0];
        $firstResult = app(CreateCuratedMerchantProductAction::class)
            ->execute($this->merchant, $firstItem->input, $firstItem->warnings);

        CuratedProductIntakeItem::query()->create([
            'curated_product_intake_run_id' => $run->id,
            'item_index' => $firstItem->itemIndex,
            'external_product_id' => $firstItem->externalProductId() ?? 'unknown',
            'product_id' => $firstResult->productId,
            'affiliate_link_id' => $firstResult->affiliateLinkId,
            'outcome' => CuratedProductIntakeItemOutcome::from($firstResult->outcome),
            'source_payload' => $firstItem->input?->sourcePayload,
            'warnings' => $firstResult->warnings !== [] ? $firstResult->warnings : null,
            'error' => $firstResult->error,
        ]);

        $run->update([
            'status' => CuratedProductIntakeRunStatus::Failed,
            'error' => 'simulated worker death',
        ]);

        $process->processRun($started->runId, $payload);
        $run->refresh();

        $this->assertSame(2, Product::query()->count());
        $this->assertSame(2, CuratedProductIntakeItem::query()->count());
        $this->assertSame(CuratedProductIntakeRunStatus::Completed, $run->status);
    }

    public function test_successful_image_acquisition_does_not_increment_preview_warning_count(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = $this->buildItems(1, 1101);
        $payload = $this->curatedPayload(['items' => $items]);

        $this->fakeEnrichmentSequence($home->id, 1);

        $result = $this->runCuratedSync($payload);

        $this->assertContains(CuratedImageAcquisitionOutcome::STATUS_ACQUIRED, $result->processedItems[0]['warnings']);
        $this->assertNotContains(CuratedImageAcquisitionOutcome::STATUS_ACQUIRED, [
            'missing_relationships',
            'missing_occasions',
        ]);
    }

    public function test_invalid_allowlisted_host_is_rejected_before_http(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = [[
            'external_product_id' => 'B0BADHOST1',
            'source_url' => 'https://www.amazon.in/dp/B0BADHOST1',
            'title' => 'Bad host gift',
            'price_amount' => '499.00',
            'price_currency' => 'INR',
            'source_image_url' => 'https://evil.example.test/image.jpg',
        ]];
        $payload = $this->curatedPayload(['items' => $items]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => ['primary_category_id' => $home->id, 'category_ids' => [$home->id]],
                ]),
            ),
        ]);

        $result = $this->runCuratedSync($payload);

        $this->assertContains(CuratedImageAcquisitionOutcome::STATUS_FAILED, $result->processedItems[0]['warnings']);
        Http::assertSentCount(1);
    }

    public function test_concurrent_identity_resolves_to_single_product(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $items = [$this->buildItem(9001)];
        $payload = $this->curatedPayload(['items' => $items]);

        $this->fakeEnrichmentSequence($home->id, 2);

        $this->runCuratedSync($payload);
        $this->runCuratedSync($payload);

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, AffiliateLink::query()->where('external_product_id', $items[0]['external_product_id'])->count());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildItems(int $count, int $startIndex = 1): array
    {
        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->buildItem($startIndex + $i);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildItem(int $index): array
    {
        $asin = 'B'.str_pad((string) $index, 9, '0', STR_PAD_LEFT);

        return [
            'external_product_id' => $asin,
            'source_url' => 'https://www.amazon.in/dp/'.$asin,
            'title' => 'Gift '.$index,
            'price_amount' => '499.00',
            'price_currency' => 'INR',
            'source_image_url' => $this->curatedImageUrlForAsin($asin),
            'availability' => 'in_stock',
        ];
    }

    private function fakeEnrichmentSequence(int $categoryId, int $count): void
    {
        Storage::fake('public');
        $imageBody = (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg'));

        Http::fake(function ($request) use ($categoryId, $imageBody) {
            $url = $request->url();

            if (str_contains($url, 'api.openai.com/v1/chat/completions')) {
                return Http::response($this->commercialEnrichmentCompletion([
                    'name' => 'Gift',
                    'taxonomy' => [
                        'primary_category_id' => $categoryId,
                        'category_ids' => [$categoryId],
                    ],
                ]));
            }

            if (str_contains($url, 'm.media-amazon.com/images/I/')) {
                return Http::response($imageBody, 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('', 404);
        });
    }

    private function seedTaxonomy(): void
    {
        Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);

        foreach ([
            'Husband', 'Boyfriend', 'Father', 'Brother', 'Son',
            'Wife', 'Girlfriend', 'Mother', 'Sister', 'Daughter',
            'Friends', 'Colleagues',
        ] as $sortOrder => $name) {
            Relationship::query()->create([
                'name' => $name,
                'slug' => str($name)->slug()->toString(),
                'sort_order' => $sortOrder + 1,
                'is_active' => true,
            ]);
        }
    }
}
