<?php

namespace Tests\Feature\CatalogAutomation;

use App\Models\CatalogAutomationRun;
use App\Models\CatalogCandidateSourcingRun;
use App\Models\Category;
use App\Models\Product;
use Database\Seeders\BudgetRangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConfiguresCommercialSourcing;
use Tests\Support\FakesCatalogImportImages;
use Tests\Support\FakesCommercialEnrichment;
use Tests\TestCase;

class AutomateCatalogCommandTest extends TestCase
{
    use ConfiguresCommercialSourcing;
    use FakesCatalogImportImages;
    use FakesCommercialEnrichment;
    use RefreshDatabase;

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

        $categoryId = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ])->id;

        $imageUrl = 'https://example.test/images/coffee-kit.jpg';

        Http::fake(function ($request) use ($categoryId, $imageUrl) {
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
                return Http::response($this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $categoryId,
                        'category_ids' => [$categoryId],
                    ],
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

    public function test_command_runs_and_prints_summary(): void
    {
        $this->artisan('catalog:automate', [
            'brief' => 'Find birthday gift ideas for husbands in India',
            '--max' => 1,
            '--candidate-limit' => 1,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Automation Run #')
            ->expectsOutputToContain('Discovery')
            ->expectsOutputToContain('Readiness');

        $this->assertSame(1, CatalogAutomationRun::query()->count());
        $this->assertSame(1, CatalogCandidateSourcingRun::query()->count());
        $this->assertSame(1, Product::query()->count());
    }

    public function test_command_dry_run_prints_skip_message_when_no_existing_candidates(): void
    {
        $this->artisan('catalog:automate', [
            'brief' => 'Find birthday gift ideas for husbands in India',
            '--max' => 1,
            '--dry-run' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run completed')
            ->expectsOutputToContain('Downstream stages skipped');

        $this->assertSame(0, CatalogAutomationRun::query()->count());
    }

    public function test_report_coverage_flag_prints_footer_without_extra_writes(): void
    {
        $productCountBefore = Product::query()->count();

        $this->artisan('catalog:automate', [
            'brief' => 'Find birthday gift ideas for husbands in India',
            '--max' => 1,
            '--candidate-limit' => 1,
            '--report-coverage' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Catalog coverage');

        $this->assertSame($productCountBefore + 1, Product::query()->count());
    }
}
