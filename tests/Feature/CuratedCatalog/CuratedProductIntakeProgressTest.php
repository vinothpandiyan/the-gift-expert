<?php

namespace Tests\Feature\CuratedCatalog;

use App\Actions\CuratedCatalog\ProcessCuratedProductIntakeAction;
use App\Actions\CuratedCatalog\ResolveCuratedProductIntakeProgressAction;
use App\Enums\CuratedProductIntakeItemOutcome;
use App\Enums\CuratedProductIntakeRunStatus;
use App\Enums\CuratedProductIntakeSourceType;
use App\Filament\Pages\CuratedProductIntake;
use App\Models\Category;
use App\Models\CuratedProductIntakeItem;
use App\Models\CuratedProductIntakeRun;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\Support\FakesCommercialEnrichment;
use Tests\Support\MakesRasterImages;
use Tests\Support\ProcessesCuratedSyncRuns;
use Tests\TestCase;

class CuratedProductIntakeProgressTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use FakesCommercialEnrichment;
    use MakesRasterImages;
    use ProcessesCuratedSyncRuns;
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->merchant = $this->configureCuratedAmazonMerchant();
    }

    public function test_progress_reports_starting_state_for_recent_run_with_zero_processed(): void
    {
        $run = $this->createRun(total: 51, status: CuratedProductIntakeRunStatus::Processing);

        $progress = app(ResolveCuratedProductIntakeProgressAction::class)->execute($run);

        $this->assertSame('starting', $progress->displayPhase);
        $this->assertSame(0, $progress->processed);
        $this->assertSame(51, $progress->remaining);
        $this->assertSame(0, $progress->percentage);
        $this->assertFalse($progress->showWorkerHint);
        $this->assertFalse($progress->isTerminal);
    }

    public function test_progress_reports_waiting_for_worker_after_grace_period(): void
    {
        config(['curated_catalog.sync.worker_wait_seconds' => 10]);

        $run = $this->createRun(total: 51, status: CuratedProductIntakeRunStatus::Processing);
        $run->update(['started_at' => now()->subSeconds(15)]);
        $run->refresh();

        $progress = app(ResolveCuratedProductIntakeProgressAction::class)->execute($run);

        $this->assertSame('waiting_for_worker', $progress->displayPhase);
        $this->assertTrue($progress->showWorkerHint);
        $this->assertSame(0, $progress->processed);
    }

    public function test_progress_reports_active_counts_from_item_rows_not_stale_run_counters(): void
    {
        $run = $this->createRun(
            total: 51,
            status: CuratedProductIntakeRunStatus::Processing,
            counters: [
                'items_created' => 0,
                'items_updated' => 0,
                'items_skipped' => 0,
                'items_failed' => 0,
            ],
        );

        for ($index = 0; $index < 11; $index++) {
            CuratedProductIntakeItem::query()->create([
                'curated_product_intake_run_id' => $run->id,
                'item_index' => $index,
                'external_product_id' => 'B'.str_pad((string) $index, 9, '0', STR_PAD_LEFT),
                'outcome' => CuratedProductIntakeItemOutcome::Created,
            ]);
        }

        $progress = app(ResolveCuratedProductIntakeProgressAction::class)->execute($run);

        $this->assertSame('processing', $progress->displayPhase);
        $this->assertSame(11, $progress->processed);
        $this->assertSame(11, $progress->created);
        $this->assertSame(40, $progress->remaining);
        $this->assertSame(21, $progress->percentage);
        $this->assertSame(0, $run->items_created);
    }

    public function test_progress_reports_per_outcome_counts_correctly(): void
    {
        $run = $this->createRun(total: 10, status: CuratedProductIntakeRunStatus::Processing);

        CuratedProductIntakeItem::query()->create([
            'curated_product_intake_run_id' => $run->id,
            'item_index' => 0,
            'external_product_id' => 'B000000001',
            'outcome' => CuratedProductIntakeItemOutcome::Created,
        ]);
        CuratedProductIntakeItem::query()->create([
            'curated_product_intake_run_id' => $run->id,
            'item_index' => 1,
            'external_product_id' => 'B000000002',
            'outcome' => CuratedProductIntakeItemOutcome::Updated,
        ]);
        CuratedProductIntakeItem::query()->create([
            'curated_product_intake_run_id' => $run->id,
            'item_index' => 2,
            'external_product_id' => 'B000000003',
            'outcome' => CuratedProductIntakeItemOutcome::Skipped,
        ]);
        CuratedProductIntakeItem::query()->create([
            'curated_product_intake_run_id' => $run->id,
            'item_index' => 3,
            'external_product_id' => 'B000000004',
            'outcome' => CuratedProductIntakeItemOutcome::Failed,
            'error' => 'invalid_item',
        ]);

        $progress = app(ResolveCuratedProductIntakeProgressAction::class)->execute($run);

        $this->assertSame(4, $progress->processed);
        $this->assertSame(1, $progress->created);
        $this->assertSame(1, $progress->updated);
        $this->assertSame(1, $progress->skipped);
        $this->assertSame(1, $progress->failed);
        $this->assertSame(6, $progress->remaining);
        $this->assertSame(40, $progress->percentage);
    }

    public function test_progress_query_uses_bounded_aggregate_queries(): void
    {
        $run = $this->createRun(total: 5, status: CuratedProductIntakeRunStatus::Processing);

        for ($index = 0; $index < 3; $index++) {
            CuratedProductIntakeItem::query()->create([
                'curated_product_intake_run_id' => $run->id,
                'item_index' => $index,
                'external_product_id' => 'B'.str_pad((string) $index, 9, '0', STR_PAD_LEFT),
                'outcome' => CuratedProductIntakeItemOutcome::Created,
            ]);
        }

        DB::enableQueryLog();

        app(ResolveCuratedProductIntakeProgressAction::class)->execute($run);

        $queries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'curated_product_intake_items'))
            ->values();

        $this->assertSame(2, $queries->count());
        $this->assertStringContainsString('count(*)', strtolower($queries[0]['query']));
        $this->assertStringContainsString('group by', strtolower($queries[1]['query']));
    }

    public function test_progress_resolution_does_not_write_products(): void
    {
        $run = $this->createRun(total: 3, status: CuratedProductIntakeRunStatus::Processing);

        CuratedProductIntakeItem::query()->create([
            'curated_product_intake_run_id' => $run->id,
            'item_index' => 0,
            'external_product_id' => 'B000000001',
            'outcome' => CuratedProductIntakeItemOutcome::Created,
        ]);

        app(ResolveCuratedProductIntakeProgressAction::class)->execute($run);

        $this->assertSame(0, Product::query()->count());
    }

    public function test_completed_run_counters_match_final_audit_counts(): void
    {
        $home = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);

        $items = [];
        for ($i = 1; $i <= 5; $i++) {
            $asin = 'B'.str_pad((string) $i, 9, '0', STR_PAD_LEFT);
            $items[] = [
                'external_product_id' => $asin,
                'source_url' => 'https://www.amazon.in/dp/'.$asin,
                'title' => 'Gift '.$i,
                'price_amount' => '499.00',
                'price_currency' => 'INR',
                'source_image_url' => 'https://m.media-amazon.com/images/I/'.$asin.'.jpg',
                'availability' => 'in_stock',
            ];
        }

        $payload = $this->curatedPayload(['items' => $items]);

        Http::fake(function ($request) use ($home) {
            if (str_contains($request->url(), 'api.openai.com')) {
                return Http::response($this->commercialEnrichmentCompletion([
                    'name' => 'Gift',
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                    ],
                ]));
            }

            return Http::response(
                (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg')),
                200,
                ['Content-Type' => 'image/jpeg'],
            );
        });
        Storage::fake('public');

        $result = $this->runCuratedSync($payload);
        $run = CuratedProductIntakeRun::query()->findOrFail($result->runId);
        $progress = app(ResolveCuratedProductIntakeProgressAction::class)->execute($run);

        $this->assertSame(CuratedProductIntakeRunStatus::Completed, $run->status);
        $this->assertSame(5, $run->items_created);
        $this->assertSame(5, $progress->created);
        $this->assertSame(5, $progress->processed);
        $this->assertSame(100, $progress->percentage);
        $this->assertTrue($progress->isTerminal);
    }

    public function test_failed_run_result_uses_item_counts_for_partial_progress(): void
    {
        $run = $this->createRun(
            total: 51,
            status: CuratedProductIntakeRunStatus::Failed,
            counters: [
                'items_created' => 0,
                'items_updated' => 0,
                'items_skipped' => 0,
                'items_failed' => 0,
            ],
            error: 'simulated worker death',
        );

        for ($index = 0; $index < 11; $index++) {
            CuratedProductIntakeItem::query()->create([
                'curated_product_intake_run_id' => $run->id,
                'item_index' => $index,
                'external_product_id' => 'B'.str_pad((string) $index, 9, '0', STR_PAD_LEFT),
                'outcome' => CuratedProductIntakeItemOutcome::Created,
            ]);
        }

        $result = app(ProcessCuratedProductIntakeAction::class)->resultFromRun($run);
        $progress = app(ResolveCuratedProductIntakeProgressAction::class)->execute($run);

        $this->assertSame(11, $result->itemsCreated);
        $this->assertSame(11, $progress->processed);
        $this->assertSame(40, $progress->remaining);
        $this->assertSame('failed', $progress->displayPhase);
        $this->assertTrue($progress->isTerminal);
    }

    public function test_polling_updates_live_progress_and_stops_on_terminal_state(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $run = $this->createRun(total: 51, status: CuratedProductIntakeRunStatus::Processing);

        for ($index = 0; $index < 11; $index++) {
            CuratedProductIntakeItem::query()->create([
                'curated_product_intake_run_id' => $run->id,
                'item_index' => $index,
                'external_product_id' => 'B'.str_pad((string) $index, 9, '0', STR_PAD_LEFT),
                'outcome' => CuratedProductIntakeItemOutcome::Created,
            ]);
        }

        $component = Livewire::test(CuratedProductIntake::class)
            ->set('activeRunId', $run->id)
            ->set('syncItemsTotal', 51)
            ->set('syncProgress', [
                'run_id' => $run->id,
                'display_phase' => 'starting',
                'total' => 51,
                'processed' => 0,
                'remaining' => 51,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'percentage' => 0,
                'show_worker_hint' => false,
            ])
            ->call('refreshSyncRun')
            ->assertSet('syncProgress.processed', 11)
            ->assertSet('syncProgress.created', 11)
            ->assertSet('syncProgress.remaining', 40)
            ->assertSet('syncProgress.percentage', 21)
            ->assertSet('activeRunId', $run->id);

        $run->update([
            'status' => CuratedProductIntakeRunStatus::Completed,
            'finished_at' => now(),
            'items_created' => 11,
            'items_total' => 51,
        ]);

        $component->call('refreshSyncRun')
            ->assertSet('activeRunId', null)
            ->assertSet('syncProgress', null)
            ->assertSet('commitResult.items_created', 11);
    }

    public function test_page_renders_progress_panel_with_live_counts(): void
    {
        $run = $this->createRun(total: 51, status: CuratedProductIntakeRunStatus::Processing);

        for ($index = 0; $index < 11; $index++) {
            CuratedProductIntakeItem::query()->create([
                'curated_product_intake_run_id' => $run->id,
                'item_index' => $index,
                'external_product_id' => 'B'.str_pad((string) $index, 9, '0', STR_PAD_LEFT),
                'outcome' => CuratedProductIntakeItemOutcome::Created,
            ]);
        }

        $this->actingAs(User::factory()->create());

        $component = Livewire::test(CuratedProductIntake::class)
            ->set('activeRunId', $run->id)
            ->set('syncItemsTotal', 51)
            ->set('syncProgress', app(ResolveCuratedProductIntakeProgressAction::class)->execute($run)->toArray())
            ->call('refreshSyncRun');

        $html = $component->html();

        $this->assertStringContainsString('data-sync-progress-panel', $html);
        $this->assertStringContainsString('11 of 51 products processed', $html);
        $this->assertStringContainsString('data-sync-progress-bar', $html);
        $this->assertStringContainsString('Sync in progress', $html);
        $this->assertStringNotContainsString('Waiting for queue worker', $html);
    }

    public function test_page_renders_completed_with_errors_warning_state(): void
    {
        $run = $this->createRun(
            total: 4,
            status: CuratedProductIntakeRunStatus::CompletedWithErrors,
            counters: [
                'items_created' => 2,
                'items_updated' => 0,
                'items_skipped' => 1,
                'items_failed' => 1,
            ],
        );

        foreach ([
            [0, CuratedProductIntakeItemOutcome::Created],
            [1, CuratedProductIntakeItemOutcome::Created],
            [2, CuratedProductIntakeItemOutcome::Skipped],
            [3, CuratedProductIntakeItemOutcome::Failed],
        ] as [$index, $outcome]) {
            CuratedProductIntakeItem::query()->create([
                'curated_product_intake_run_id' => $run->id,
                'item_index' => $index,
                'external_product_id' => 'B'.str_pad((string) ($index + 1), 9, '0', STR_PAD_LEFT),
                'outcome' => $outcome,
                'error' => $outcome === CuratedProductIntakeItemOutcome::Failed ? 'invalid_item' : null,
            ]);
        }

        $this->actingAs(User::factory()->create());

        $component = Livewire::test(CuratedProductIntake::class)
            ->set('activeRunId', $run->id)
            ->call('refreshSyncRun');

        $html = $component->html();

        $this->assertStringContainsString('Sync completed with issues', $html);
        $this->assertStringContainsString('data-sync-warning-summary', $html);
        $this->assertStringContainsString('data-result-status="completed_with_errors"', $html);
    }

    /**
     * @param  array<string, int>  $counters
     */
    private function createRun(
        int $total,
        CuratedProductIntakeRunStatus $status,
        array $counters = [],
        ?string $error = null,
    ): CuratedProductIntakeRun {
        return CuratedProductIntakeRun::query()->create([
            'merchant_id' => $this->merchant->id,
            'source_type' => CuratedProductIntakeSourceType::BrowserJson,
            'status' => $status,
            'started_at' => now(),
            'finished_at' => in_array($status, [
                CuratedProductIntakeRunStatus::Completed,
                CuratedProductIntakeRunStatus::CompletedWithErrors,
                CuratedProductIntakeRunStatus::Failed,
            ], true) ? now() : null,
            'items_total' => $total,
            'items_created' => $counters['items_created'] ?? 0,
            'items_updated' => $counters['items_updated'] ?? 0,
            'items_skipped' => $counters['items_skipped'] ?? 0,
            'items_failed' => $counters['items_failed'] ?? 0,
            'error' => $error,
        ]);
    }
}
