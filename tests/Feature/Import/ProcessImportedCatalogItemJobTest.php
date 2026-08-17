<?php

namespace Tests\Feature\Import;

use App\Actions\Import\ImportCatalogAction;
use App\Actions\Import\ProcessImportedCatalogItemAction;
use App\Enums\ImportRunItemStatus;
use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Jobs\ProcessImportedCatalogItemJob;
use App\Models\AffiliateLink;
use App\Models\ImportRun;
use App\Models\ImportRunItem;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FakesCatalogImportImages;
use Tests\TestCase;

class ProcessImportedCatalogItemJobTest extends TestCase
{
    use FakesCatalogImportImages;
    use RefreshDatabase;

    public function test_job_creates_a_draft_product_and_affiliate_link(): void
    {
        $this->fakeCatalogImageHttp();
        $item = $this->pendingItem($this->walletPayload());

        ProcessImportedCatalogItemJob::dispatchSync($item->id);

        $item->refresh();
        $this->assertSame(ImportRunItemStatus::Succeeded, $item->status);
        $this->assertNotNull($item->product_id);
        $this->assertNotNull($item->affiliate_link_id);

        $product = Product::query()->findOrFail($item->product_id);
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertNull($product->published_at);
        $this->assertSame('Classic Leather Wallet', $product->name);
        $this->assertDatabaseHas('affiliate_links', [
            'id' => $item->affiliate_link_id,
            'external_product_id' => 'FAKE-WALLET-1',
        ]);
    }

    public function test_job_stores_images_through_the_existing_pipeline(): void
    {
        $hashes = $this->fakeCatalogImageHttp();
        $item = $this->pendingItem($this->walletPayload());

        ProcessImportedCatalogItemJob::dispatchSync($item->id);

        $product = Product::query()->findOrFail($item->fresh()->product_id);
        $images = $product->images()->orderBy('sort_order')->get();

        $this->assertCount(2, $images);
        $this->assertTrue($images[0]->is_primary);
        $this->assertSame($hashes['https://example.test/images/wallet-1.jpg'], $images[0]->content_hash);
        $this->assertTrue(Storage::disk('public')->exists($images[0]->path));
    }

    public function test_handling_the_same_job_twice_is_idempotent(): void
    {
        $this->fakeCatalogImageHttp();
        $item = $this->pendingItem($this->walletPayload());
        $job = new ProcessImportedCatalogItemJob($item->id);

        $job->handle(app(ProcessImportedCatalogItemAction::class));
        $job->handle(app(ProcessImportedCatalogItemAction::class));

        $run = $item->importRun()->firstOrFail();

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, AffiliateLink::query()->count());
        $this->assertCount(2, ProductImage::query()->get());
        $this->assertSame(1, $run->fresh()->items_succeeded);
        $this->assertSame(ImportRunStatus::Completed, $run->fresh()->status);
    }

    public function test_missing_name_or_url_skips_the_item_once(): void
    {
        $item = $this->pendingItem([
            'affiliate_url' => 'https://example.test/affiliate/missing-name',
            'external_product_id' => 'FAKE-MISSING-NAME',
        ]);

        $job = new ProcessImportedCatalogItemJob($item->id);
        $job->handle(app(ProcessImportedCatalogItemAction::class));
        $job->handle(app(ProcessImportedCatalogItemAction::class));

        $item->refresh();
        $run = $item->importRun()->firstOrFail();

        $this->assertSame(ImportRunItemStatus::Skipped, $item->status);
        $this->assertSame('A gift name is required.', $item->error);
        $this->assertSame(1, $run->items_skipped);
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(ImportRunStatus::CompletedWithErrors, $run->status);
    }

    public function test_image_failure_succeeds_the_item_without_incrementing_failed(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response('missing', 404),
        ]);

        $item = $this->pendingItem($this->walletPayload());

        ProcessImportedCatalogItemJob::dispatchSync($item->id);

        $item->refresh();
        $run = $item->importRun()->firstOrFail();

        $this->assertSame(ImportRunItemStatus::Succeeded, $item->status);
        $this->assertSame(0, $run->items_failed);
        $this->assertSame(1, $run->items_succeeded);
        $this->assertNotEmpty($item->error);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(0, ProductImage::query()->count());
    }

    public function test_failed_callback_marks_a_pending_item_failed_once(): void
    {
        $item = $this->pendingItem($this->walletPayload(), total: 1);
        $job = new ProcessImportedCatalogItemJob($item->id);

        $job->failed(new RuntimeException('Unexpected worker failure.'));
        $job->failed(new RuntimeException('Unexpected worker failure.'));

        $item->refresh();
        $run = $item->importRun()->firstOrFail();

        $this->assertSame(ImportRunItemStatus::Failed, $item->status);
        $this->assertSame('Unexpected worker failure.', $item->error);
        $this->assertSame(1, $run->items_failed);
        $this->assertSame(ImportRunStatus::CompletedWithErrors, $run->status);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_unexpected_job_exception_writes_failed_jobs_and_fails_the_item_once(): void
    {
        $item = $this->pendingItem($this->walletPayload(), total: 1);

        $this->app->bind(ProcessImportedCatalogItemAction::class, function () {
            return new class extends ProcessImportedCatalogItemAction
            {
                public function __construct() {}

                public function execute(ImportRunItem $item): void
                {
                    throw new RuntimeException('Unexpected worker failure.');
                }
            };
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            app('queue.failer')->log(
                $event->connectionName,
                $event->job->getQueue(),
                $event->job->getRawBody(),
                $event->exception,
            );
        });

        try {
            ProcessImportedCatalogItemJob::dispatchSync($item->id);
            $this->fail('Expected the job to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unexpected worker failure.', $exception->getMessage());
        }

        $item->refresh();
        $run = $item->importRun()->firstOrFail();

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame(ImportRunItemStatus::Failed, $item->status);
        $this->assertSame(1, $run->items_failed);
        $this->assertSame(ImportRunStatus::CompletedWithErrors, $run->status);
    }

    public function test_full_sync_fixture_completes_with_errors(): void
    {
        $this->fakeCatalogImageHttp();
        $merchant = $this->fakeMerchant();

        $run = app(ImportCatalogAction::class)->execute($merchant);

        $this->assertSame(ImportRunStatus::CompletedWithErrors, $run->status);
        $this->assertSame(5, $run->items_total);
        $this->assertSame(2, $run->items_succeeded);
        $this->assertSame(3, $run->items_skipped);
        $this->assertSame(0, $run->items_failed);
    }

    public function test_clean_fixture_completes_without_errors(): void
    {
        $this->fakeCatalogImageHttp();
        $merchant = $this->fakeMerchant();
        config()->set('import.providers.fake.fixture', base_path('tests/Fixtures/import/catalog-updated.json'));

        $run = app(ImportCatalogAction::class)->execute($merchant);

        $this->assertSame(ImportRunStatus::Completed, $run->status);
        $this->assertSame(2, $run->items_total);
        $this->assertSame(2, $run->items_succeeded);
        $this->assertSame(0, $run->items_skipped);
        $this->assertSame(0, $run->items_failed);
        $this->assertNotNull($run->finished_at);
    }

    public function test_provider_failure_marks_the_run_failed_without_jobs(): void
    {
        Queue::fake();

        $merchant = Merchant::query()->create([
            'name' => 'Unknown Network',
            'slug' => 'unknown-network',
            'affiliate_network' => 'not_a_provider',
        ]);

        try {
            app(ImportCatalogAction::class)->execute($merchant);
            $this->fail('Expected provider resolution to fail.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('not_a_provider', $exception->getMessage());
        }

        $this->assertSame(0, Product::query()->count());
        $this->assertDatabaseHas('import_runs', [
            'merchant_id' => $merchant->id,
            'status' => ImportRunStatus::Failed->value,
        ]);
        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pendingItem(array $payload, int $total = 1): ImportRunItem
    {
        $merchant = $this->fakeMerchant();
        $run = ImportRun::query()->create([
            'merchant_id' => $merchant->id,
            'provider_key' => $merchant->affiliate_network,
            'status' => ImportRunStatus::Running,
            'started_at' => now(),
            'items_total' => $total,
        ]);

        return ImportRunItem::query()->create([
            'import_run_id' => $run->id,
            'external_product_id' => (string) $payload['external_product_id'],
            'status' => ImportRunItemStatus::Pending,
            'source_payload' => $payload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function walletPayload(): array
    {
        return [
            'name' => 'Classic Leather Wallet',
            'short_description' => 'A slim leather wallet for everyday carry.',
            'description' => 'Imported sample wallet with a deterministic fake catalog identity.',
            'brand' => 'Example Brand',
            'price_amount' => '1899.00',
            'price_currency' => 'INR',
            'affiliate_url' => 'https://example.test/affiliate/wallet',
            'external_product_id' => 'FAKE-WALLET-1',
            'image_urls' => [
                'https://example.test/images/wallet-1.jpg',
                'https://example.test/images/wallet-2.jpg',
            ],
        ];
    }

    private function fakeMerchant(): Merchant
    {
        return Merchant::query()->create([
            'name' => 'Fake Merchant',
            'slug' => 'fake-merchant-'.uniqid(),
            'affiliate_network' => 'fake',
        ]);
    }
}
