<?php

namespace Tests\Feature\Import;

use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Jobs\ProcessImportedCatalogItemJob;
use App\Models\AffiliateLink;
use App\Models\ImportRun;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesCatalogImportImages;
use Tests\TestCase;

class ImportCatalogCommandTest extends TestCase
{
    use FakesCatalogImportImages;
    use RefreshDatabase;

    public function test_unknown_merchant_slug_fails_without_creating_a_run(): void
    {
        Queue::fake();

        $this->artisan('catalog:import', ['merchant' => 'missing-merchant'])
            ->assertFailed();

        $this->assertSame(0, ImportRun::query()->count());
        $this->assertSame(0, Product::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_unknown_provider_fails_before_dispatching_jobs(): void
    {
        Queue::fake();

        $merchant = Merchant::query()->create([
            'name' => 'Unknown Network',
            'slug' => 'unknown-network',
            'affiliate_network' => 'not_a_provider',
        ]);

        $this->artisan('catalog:import', ['merchant' => $merchant->slug])
            ->assertFailed();

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, AffiliateLink::query()->count());
        $this->assertDatabaseHas('import_runs', [
            'merchant_id' => $merchant->id,
            'provider_key' => 'not_a_provider',
            'status' => ImportRunStatus::Failed->value,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_known_merchant_creates_a_run_and_dispatches_one_job_per_pending_item(): void
    {
        Queue::fake();
        $merchant = $this->fakeMerchant();

        $this->artisan('catalog:import', ['merchant' => $merchant->slug])
            ->assertSuccessful();

        $run = ImportRun::query()->firstOrFail();

        $this->assertSame(ImportRunStatus::Running, $run->status);
        $this->assertSame(5, $run->items_total);
        $this->assertSame(1, $run->items_skipped);
        $this->assertSame(0, $run->items_succeeded);
        $this->assertSame(0, $run->items_failed);
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(4, $run->items()->count());
        Queue::assertPushed(ProcessImportedCatalogItemJob::class, 4);
    }

    public function test_dry_run_is_write_free_and_still_resolves_the_provider(): void
    {
        Queue::fake();
        $merchant = $this->fakeMerchant();

        $this->artisan('catalog:import', [
            'merchant' => $merchant->slug,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run for merchant [fake-merchant] using provider [fake].')
            ->expectsOutputToContain('Valid: 2')
            ->expectsOutputToContain('Skipped: 3')
            ->assertSuccessful();

        $this->assertSame(0, ImportRun::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, AffiliateLink::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_dry_run_unknown_provider_fails_without_database_writes(): void
    {
        Queue::fake();

        $merchant = Merchant::query()->create([
            'name' => 'Unknown Network',
            'slug' => 'unknown-network',
            'affiliate_network' => 'not_a_provider',
        ]);

        $this->artisan('catalog:import', [
            'merchant' => $merchant->slug,
            '--dry-run' => true,
        ])->assertFailed();

        $this->assertSame(0, ImportRun::query()->count());
        $this->assertSame(0, Product::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_sync_import_finishes_the_run_with_drafts_and_does_not_publish(): void
    {
        $this->fakeCatalogImageHttp();
        $merchant = $this->fakeMerchant();

        $this->artisan('catalog:import', ['merchant' => $merchant->slug])
            ->assertSuccessful();

        $run = ImportRun::query()->firstOrFail();

        $this->assertSame(ImportRunStatus::CompletedWithErrors, $run->status);
        $this->assertSame(5, $run->items_total);
        $this->assertSame(2, $run->items_succeeded);
        $this->assertSame(3, $run->items_skipped);
        $this->assertSame(0, $run->items_failed);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(2, Product::query()->count());
        $this->assertTrue(Product::query()->get()->every(
            fn (Product $product): bool => $product->status === ProductStatus::Draft && $product->published_at === null,
        ));
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
