<?php

namespace Tests\Feature\CommercialSourcing;

use App\Actions\CatalogCandidate\SourceCatalogCandidatesAction;
use App\Enums\CatalogCandidateSourcingItemStatus;
use App\Enums\CatalogCandidateStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateSourcingItem;
use App\Models\CatalogCandidateSourcingRun;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductImage;
use Database\Seeders\BudgetRangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\ConfiguresCommercialSourcing;
use Tests\Support\FakesCommercialEnrichment;
use Tests\TestCase;

class SourceCatalogCandidatesEnrichmentTest extends TestCase
{
    use ConfiguresCommercialSourcing;
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
                'affiliate' => [
                    'strategy' => 'query_param',
                    'param' => 'aff',
                    'value' => 'test-tag',
                ],
            ]),
        ]);
        $this->createActiveMerchant('partner-a');
        Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
    }

    public function test_enrich_persists_a_compact_snapshot_without_catalog_writes(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->first();
        $this->fakeSearchAndEnrichment($home->id);
        $before = $this->catalogCounts();
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        $result = app(SourceCatalogCandidatesAction::class)->execute(
            candidateId: $candidate->id,
            enrich: true,
        );

        $item = CatalogCandidateSourcingItem::query()->first();

        $this->assertSame(1, $result->itemsSucceeded);
        $this->assertNotNull($item);
        $this->assertNotNull($item->enrichment);
        $this->assertSame($item->id, $item->enrichment['sourcing_item_id']);
        $this->assertSame('BrandX French Press', $item->enrichment['name']);
        $this->assertTrue($item->enrichment['affiliate_ready']);
        $this->assertNull($item->product_id);
        $this->assertNull($item->affiliate_link_id);
        $this->assertNull($item->readiness);
        $this->assertSame($before, $this->catalogCounts());
        $this->assertSame(14, $this->discoveryRouteCount());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/chat/completions');
    }

    public function test_without_enrich_selected_offer_is_unchanged_and_enrichment_is_null(): void
    {
        $this->fakeTavily();
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        app(SourceCatalogCandidatesAction::class)->execute(candidateId: $candidate->id);

        $item = CatalogCandidateSourcingItem::query()->first();
        $this->assertNotNull($item);
        $this->assertNull($item->enrichment);
        $this->assertSame('B0ABCDEFGH', $item->selected_offer['external_product_id']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'chat/completions'));
    }

    public function test_dry_run_enrich_writes_nothing(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->first();
        $this->fakeSearchAndEnrichment($home->id);
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        $result = app(SourceCatalogCandidatesAction::class)->execute(
            candidateId: $candidate->id,
            enrich: true,
            dryRun: true,
        );

        $this->assertTrue($result->dryRun);
        $this->assertSame(1, $result->itemsSucceeded);
        $this->assertNotNull($result->outcomes[0]->payload);
        $this->assertSame(0, CatalogCandidateSourcingRun::query()->count());
        $this->assertSame(0, CatalogCandidateSourcingItem::query()->count());
        $this->assertSame(0, Product::query()->count());
    }

    public function test_enrich_item_skips_search_and_updates_the_existing_row(): void
    {
        $this->fakeTavily();
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);
        app(SourceCatalogCandidatesAction::class)->execute(candidateId: $candidate->id);
        $item = CatalogCandidateSourcingItem::query()->first();
        $this->assertNull($item->enrichment);

        $home = Category::query()->where('slug', 'home-and-living')->first();
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

        $result = app(SourceCatalogCandidatesAction::class)->execute(
            enrichItemId: $item->id,
        );

        $this->assertSame(1, $result->itemsSucceeded);
        $this->assertSame(1, CatalogCandidateSourcingRun::query()->count());
        $this->assertSame(1, CatalogCandidateSourcingItem::query()->count());
        $this->assertNotNull($item->fresh()->enrichment);
        $this->assertSame(CatalogCandidateSourcingItemStatus::Succeeded, $item->fresh()->status);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
    }

    public function test_malformed_enrichment_fails_the_item_without_catalog_writes(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => [
                    [
                        'title' => 'BrandX French Press',
                        'url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                        'content' => 'BrandX French Press ₹1,299',
                    ],
                ],
            ], 200),
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{']]],
            ]),
        ]);
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);
        $before = $this->catalogCounts();

        $result = app(SourceCatalogCandidatesAction::class)->execute(
            candidateId: $candidate->id,
            enrich: true,
        );

        $item = CatalogCandidateSourcingItem::query()->first();
        $this->assertSame(1, $result->itemsFailed);
        $this->assertSame(CatalogCandidateSourcingItemStatus::Failed, $item->status);
        $this->assertNull($item->enrichment);
        $this->assertNotNull($item->selected_offer);
        $this->assertSame($before, $this->catalogCounts());
    }

    private function fakeTavily(): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => [
                    [
                        'title' => 'BrandX French Press',
                        'url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                        'content' => 'BrandX French Press ₹1,299',
                    ],
                ],
            ], 200),
        ]);
    }

    private function fakeSearchAndEnrichment(int $categoryId): void
    {
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => [
                    [
                        'title' => 'BrandX French Press',
                        'url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                        'content' => 'BrandX French Press ₹1,299',
                    ],
                ],
            ], 200),
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $categoryId,
                        'category_ids' => [$categoryId],
                    ],
                ]),
            ),
        ]);
    }

    private function discoveryRouteCount(): int
    {
        return collect(Route::getRoutes())->filter(
            fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'),
        )->count();
    }

    /**
     * @return array<string, int>
     */
    private function catalogCounts(): array
    {
        return [
            'products' => Product::query()->withTrashed()->count(),
            'affiliate_links' => AffiliateLink::query()->withTrashed()->count(),
            'product_images' => ProductImage::query()->count(),
            'import_runs' => ImportRun::query()->count(),
            'category_product' => DB::table('category_product')->count(),
            'occasion_product' => DB::table('occasion_product')->count(),
            'relationship_product' => DB::table('relationship_product')->count(),
            'recipient_type_product' => DB::table('recipient_type_product')->count(),
            'interest_product' => DB::table('interest_product')->count(),
            'profession_product' => DB::table('profession_product')->count(),
            'gift_type_product' => DB::table('gift_type_product')->count(),
        ];
    }
}
