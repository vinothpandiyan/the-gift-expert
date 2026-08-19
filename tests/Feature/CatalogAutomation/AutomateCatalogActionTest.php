<?php

namespace Tests\Feature\CatalogAutomation;

use App\Actions\CatalogCandidate\AutomateCatalogAction;
use App\Actions\Product\PublishProductAction;
use App\CatalogAutomation\CatalogAutomationOptions;
use App\Enums\CatalogAutomationRunStatus;
use App\Enums\CatalogAutomationStage;
use App\Enums\CatalogCandidateStatus;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogAutomationRun;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateDiscoveryRun;
use App\Models\CatalogCandidateEvidence;
use App\Models\CatalogCandidateIngestionRun;
use App\Models\CatalogCandidateSourcingItem;
use App\Models\CatalogCandidateSourcingRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Database\Seeders\BudgetRangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\Support\ConfiguresCommercialSourcing;
use Tests\Support\FakesCatalogImportImages;
use Tests\Support\FakesCommercialEnrichment;
use Tests\TestCase;

class AutomateCatalogActionTest extends TestCase
{
    use ConfiguresCommercialSourcing;
    use FakesCatalogImportImages;
    use FakesCommercialEnrichment;
    use RefreshDatabase;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureCommercialEnrichment();
        $this->seed(BudgetRangeSeeder::class);
        $this->useCommercialMerchants([
            'partner-a' => $this->commercialMerchantConfig('partner-a', [
                'domains' => ['partner-a.example'],
                'image_policy_key' => 'fake',
                'affiliate' => [
                    'strategy' => 'query_param',
                    'param' => 'aff',
                    'value' => 'test-tag',
                ],
            ]),
        ]);
        $this->createActiveMerchant('partner-a');
        $this->categoryId = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ])->id;
    }

    public function test_happy_path_runs_discovery_sourcing_promotion_and_readiness_without_publish(): void
    {
        $this->fakeCommercialPipeline();
        $publish = Mockery::mock(PublishProductAction::class);
        $publish->shouldNotReceive('execute');
        $this->app->instance(PublishProductAction::class, $publish);

        $result = app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'max' => 1,
            'candidate_limit' => 1,
        ]));

        $this->assertNotNull($result->automationRunId);
        $this->assertSame(1, CatalogCandidateSourcingRun::query()->count());
        $this->assertSame(1, $result->candidatesPromoted);
        $this->assertSame(ProductStatus::Draft, Product::query()->first()->status);
        $this->assertNull(Product::query()->first()->published_at);
        $this->assertNotNull(CatalogCandidateSourcingItem::query()->first()->readiness);
        $this->assertSame(CatalogAutomationRunStatus::Completed, CatalogAutomationRun::query()->first()->status);
        $this->assertSame(14, $this->discoveryRouteCount());
    }

    public function test_full_automation_creates_only_one_sourcing_run(): void
    {
        $this->fakeCommercialPipeline();

        app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'max' => 2,
            'candidate_limit' => 2,
        ]));

        $this->assertSame(1, CatalogCandidateSourcingRun::query()->count());
    }

    public function test_stop_after_discovery_does_not_create_sourcing_run(): void
    {
        $result = app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'stop_after' => 'discovery',
            'max' => 1,
        ]));

        $this->assertSame(CatalogAutomationStage::Discovery, $result->stoppedAfter);
        $this->assertSame(0, CatalogCandidateSourcingRun::query()->count());
        $this->assertNull($result->sourcingRunId);
    }

    public function test_stop_after_sourcing_persists_offer_without_enrichment_or_product(): void
    {
        $this->fakeCommercialPipeline();

        app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'stop_after' => 'sourcing',
            'max' => 1,
            'candidate_limit' => 1,
        ]));

        $item = CatalogCandidateSourcingItem::query()->first();
        $this->assertNotNull($item?->selected_offer);
        $this->assertNull($item->enrichment);
        $this->assertNull($item->product_id);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_stop_after_enrichment_persists_enrichment_without_product(): void
    {
        $this->fakeCommercialPipeline();

        app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'stop_after' => 'enrichment',
            'max' => 1,
            'candidate_limit' => 1,
        ]));

        $item = CatalogCandidateSourcingItem::query()->first();
        $this->assertNotNull($item?->enrichment);
        $this->assertNull($item->product_id);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_stop_after_promotion_creates_product_without_terminal_readiness_re_evaluation(): void
    {
        $this->fakeCommercialPipeline();

        $result = app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'stop_after' => 'promotion',
            'max' => 1,
            'candidate_limit' => 1,
        ]));

        $this->assertSame(1, $result->candidatesPromoted);
        $this->assertSame(0, $result->ready);
        $this->assertSame(0, $result->needsReview);
        $this->assertSame(0, $result->blocked);
        $this->assertNotNull(CatalogCandidateSourcingItem::query()->first()?->product_id);
    }

    public function test_stop_after_readiness_runs_terminal_re_evaluation(): void
    {
        $this->fakeCommercialPipeline();

        $result = app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'stop_after' => 'readiness',
            'max' => 1,
            'candidate_limit' => 1,
        ]));

        $this->assertSame(
            $result->ready + $result->needsReview + $result->blocked,
            1,
        );
    }

    public function test_duplicate_discovery_continues_existing_eligible_candidate_without_duplicating_catalog_rows(): void
    {
        CatalogCandidate::factory()->create([
            'title' => 'Portable Photo Printer',
        ]);

        $this->fakeCommercialPipeline();

        $before = [
            'candidates' => CatalogCandidate::query()->count(),
            'products' => Product::query()->count(),
            'affiliate_links' => AffiliateLink::query()->count(),
            'product_images' => ProductImage::query()->count(),
        ];

        $result = app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'max' => 1,
            'candidate_limit' => 1,
        ]));

        $this->assertSame(1, $result->existingCandidatesContinued);
        $this->assertSame(0, $result->candidatesDuplicate);
        $this->assertSame(0, $result->candidatesAdded);
        $this->assertSame($before['candidates'], CatalogCandidate::query()->count());
        $this->assertSame(1, $result->candidatesPromoted);
        $this->assertSame($before['products'] + 1, Product::query()->count());
        $this->assertSame($before['affiliate_links'] + 1, AffiliateLink::query()->count());
    }

    public function test_already_promoted_candidate_is_skipped_by_default(): void
    {
        $this->fakeCommercialPipeline();

        app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'max' => 1,
            'candidate_limit' => 1,
        ]));

        $productCount = Product::query()->count();

        $result = app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'max' => 1,
            'candidate_limit' => 1,
        ]));

        $this->assertGreaterThanOrEqual(1, $result->alreadyPromotedSkipped);
        $this->assertSame($productCount, Product::query()->count());
        $this->assertSame(1, CatalogCandidateSourcingRun::query()->count());
    }

    public function test_rejected_and_under_review_candidates_are_never_auto_processed(): void
    {
        CatalogCandidate::factory()->create([
            'title' => 'Portable Photo Printer',
            'status' => CatalogCandidateStatus::Rejected,
        ]);
        CatalogCandidate::factory()->create([
            'title' => 'Smart Temperature-Control Mug',
            'status' => CatalogCandidateStatus::UnderReview,
        ]);

        $this->fakeCommercialPipeline();

        $result = app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'max' => 3,
            'candidate_limit' => 1,
        ]));

        $this->assertSame(2, $result->candidatesDuplicate);
        $this->assertSame(0, $result->existingCandidatesContinued);
        $this->assertSame(1, $result->candidatesAdded);
        $this->assertSame(1, $result->candidatesPromoted);
    }

    public function test_discovered_candidates_are_processed_by_default_gate(): void
    {
        $this->fakeCommercialPipeline();

        app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'max' => 1,
            'candidate_limit' => 1,
        ]));

        $candidate = CatalogCandidate::query()->first();
        $this->assertSame(CatalogCandidateStatus::Discovered, $candidate->status);
        $this->assertNotNull(CatalogCandidateSourcingItem::query()->first());
    }

    public function test_candidate_limit_is_enforced(): void
    {
        $this->fakeCommercialPipeline();

        $result = app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'max' => 3,
            'candidate_limit' => 1,
        ]));

        $this->assertSame(1, CatalogCandidateSourcingItem::query()->count());
        $this->assertSame(1, $result->candidatesSourced);
    }

    public function test_dry_run_writes_nothing(): void
    {
        CatalogCandidate::factory()->create([
            'title' => 'Portable Photo Printer',
        ]);
        $this->fakeCommercialPipeline();

        $result = app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'dry_run' => true,
            'max' => 1,
            'candidate_limit' => 1,
        ]));

        $this->assertTrue($result->dryRun);
        $this->assertSame(0, CatalogAutomationRun::query()->count());
        $this->assertSame(1, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateEvidence::query()->count());
        $this->assertSame(0, CatalogCandidateDiscoveryRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        $this->assertSame(0, CatalogCandidateSourcingRun::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, DB::table('category_product')->count());
    }

    public function test_dry_run_with_only_new_proposals_skips_downstream_honestly(): void
    {
        $result = app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'dry_run' => true,
            'max' => 1,
        ]));

        $this->assertTrue($result->downstreamSkipped);
        $this->assertStringContainsString(
            'Downstream stages skipped',
            (string) $result->downstreamSkippedReason,
        );
        $this->assertSame(0, CatalogCandidateSourcingRun::query()->count());
    }

    public function test_discovery_fatal_failure_marks_automation_run_failed_without_sourcing_run(): void
    {
        config(['catalog_candidate_discovery.provider' => 'missing']);

        try {
            app(AutomateCatalogAction::class)->execute($this->automationOptions());
            $this->fail('Expected discovery provider failure.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $run = CatalogAutomationRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame(CatalogAutomationRunStatus::Failed, $run->status);
        $this->assertSame(0, CatalogCandidateSourcingRun::query()->count());
    }

    public function test_one_enrichment_failure_allows_others_to_continue_with_completed_with_errors(): void
    {
        $this->fakeCommercialPipeline(enrichmentFailuresBeforeSuccess: 1);

        $result = app(AutomateCatalogAction::class)->execute($this->automationOptions([
            'max' => 2,
            'candidate_limit' => 2,
            'stop_after' => 'enrichment',
        ]));

        $this->assertSame(1, $result->candidatesEnriched);
        $this->assertNotEmpty($result->failures);
        $this->assertSame(CatalogAutomationRunStatus::CompletedWithErrors, CatalogAutomationRun::query()->first()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function automationOptions(array $overrides = []): CatalogAutomationOptions
    {
        return CatalogAutomationOptions::from(array_merge([
            'brief' => 'Find birthday gift ideas for husbands in India',
            'market' => 'IN',
            'max' => 1,
            'freshness_days' => 30,
            'stop_after' => 'readiness',
        ], $overrides));
    }

    private function fakeCommercialPipeline(int $enrichmentFailuresBeforeSuccess = 0): void
    {
        $imageUrl = 'https://example.test/images/coffee-kit.jpg';
        $openAiFailures = $enrichmentFailuresBeforeSuccess;
        $taxonomy = [
            'primary_category_id' => $this->categoryId,
            'category_ids' => [$this->categoryId],
        ];

        Http::fake(function ($request) use ($imageUrl, &$openAiFailures, $taxonomy) {
            $url = $request->url();

            if (str_contains($url, 'api.tavily.com/search')) {
                return Http::response([
                    'results' => [[
                        'title' => 'BrandX French Press',
                        'url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                        'content' => 'BrandX French Press ₹1,299',
                        'image' => $imageUrl,
                    ]],
                ], 200);
            }

            if (str_contains($url, 'api.openai.com/v1/chat/completions')) {
                if ($openAiFailures > 0) {
                    $openAiFailures--;

                    return Http::response(['error' => 'temporary'], 500);
                }

                return Http::response($this->commercialEnrichmentCompletion([
                    'taxonomy' => $taxonomy,
                ]), 200);
            }

            if ($url === $imageUrl) {
                return Http::response(
                    (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg')),
                    200,
                    ['Content-Type' => 'image/jpeg'],
                );
            }

            return Http::response([], 404);
        });
    }

    private function discoveryRouteCount(): int
    {
        return collect(Route::getRoutes())->filter(
            fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'),
        )->count();
    }
}
