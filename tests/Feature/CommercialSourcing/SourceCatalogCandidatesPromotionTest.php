<?php

namespace Tests\Feature\CommercialSourcing;

use App\Actions\CatalogCandidate\PromoteCatalogCandidateSourcingItemAction;
use App\Actions\CatalogCandidate\SourceCatalogCandidatesAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\CatalogCandidateSourcingItemStatus;
use App\Enums\CatalogCandidateStatus;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateSourcingItem;
use App\Models\CatalogCandidateSourcingRun;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Merchant;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductImage;
use Database\Seeders\BudgetRangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConfiguresCommercialSourcing;
use Tests\Support\FakesCatalogImportImages;
use Tests\Support\FakesCommercialEnrichment;
use Tests\TestCase;

class SourceCatalogCandidatesPromotionTest extends TestCase
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
    }

    public function test_promote_creates_draft_product_affiliate_link_and_taxonomy(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->first();
        $birthday = Occasion::query()->where('slug', 'birthday')->first();
        $this->fakeSearchAndEnrichment($home->id, $birthday->id);
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        $this->artisan('catalog-candidates:source', [
            '--candidate' => $candidate->id,
            '--promote' => true,
        ])->assertSuccessful();

        $item = CatalogCandidateSourcingItem::query()->first();
        $product = Product::query()->first();
        $link = AffiliateLink::query()->first();

        $this->assertNotNull($item);
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame($link->id, $item->affiliate_link_id);
        $this->assertNotNull($item->selected_offer);
        $this->assertNotNull($item->enrichment);
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertNull($product->published_at);
        $this->assertSame('BrandX French Press', $product->name);
        $this->assertSame('B0ABCDEFGH', $link->external_product_id);
        $this->assertSame(AffiliateLinkStatus::Active, $link->status);
        $this->assertTrue($link->url !== null && str_contains($link->url, 'aff=test-tag'));
        $this->assertTrue($product->categories()->where('categories.id', $home->id)->exists());
        $this->assertTrue((bool) $product->categories()->where('categories.id', $home->id)->first()->pivot->is_primary);
        $this->assertTrue($product->occasions()->where('occasions.id', $birthday->id)->exists());
        $this->assertSame(0, ImportRun::query()->count());
        $this->assertSame(14, $this->discoveryRouteCount());
    }

    public function test_promote_is_idempotent_for_same_merchant_and_external_product_id(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->first();
        $this->fakeSearchAndEnrichment($home->id);
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        app(SourceCatalogCandidatesAction::class)->execute(
            candidateId: $candidate->id,
            enrich: true,
            promote: true,
        );

        $firstProductId = Product::query()->value('id');
        $firstLinkId = AffiliateLink::query()->value('id');
        $item = CatalogCandidateSourcingItem::query()->first();

        app(PromoteCatalogCandidateSourcingItemAction::class)->execute($item->fresh());

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, AffiliateLink::query()->count());
        $this->assertSame($firstProductId, Product::query()->value('id'));
        $this->assertSame($firstLinkId, AffiliateLink::query()->value('id'));
        $this->assertSame($firstProductId, $item->fresh()->product_id);
        $this->assertSame($firstLinkId, $item->fresh()->affiliate_link_id);
    }

    public function test_promotion_gate_failure_creates_no_product_or_affiliate_link(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->first();
        $this->fakeSearchAndEnrichment($home->id, affiliateReady: false);
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);
        $before = $this->catalogCounts();

        $result = app(SourceCatalogCandidatesAction::class)->execute(
            candidateId: $candidate->id,
            enrich: true,
            promote: true,
        );

        $this->assertSame(1, $result->itemsFailed);
        $this->assertSame($before, $this->catalogCounts());
        $this->assertNull(CatalogCandidateSourcingItem::query()->value('product_id'));
    }

    public function test_dry_run_promote_writes_nothing(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->first();
        $this->fakeSearchAndEnrichment($home->id);
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);
        $before = $this->catalogCounts();

        $result = app(SourceCatalogCandidatesAction::class)->execute(
            candidateId: $candidate->id,
            enrich: true,
            promote: true,
            dryRun: true,
        );

        $this->assertTrue($result->dryRun);
        $this->assertSame(1, $result->itemsSucceeded);
        $this->assertNull($result->outcomes[0]->productId);
        $this->assertSame($before, $this->catalogCounts());
        $this->assertSame(0, CatalogCandidateSourcingRun::query()->count());
        $this->assertSame(0, CatalogCandidateSourcingItem::query()->count());
    }

    public function test_promote_item_promotes_an_existing_enriched_row(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->first();
        $this->fakeSearchAndEnrichment($home->id);
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);
        app(SourceCatalogCandidatesAction::class)->execute(
            candidateId: $candidate->id,
            enrich: true,
        );
        $item = CatalogCandidateSourcingItem::query()->first();

        Http::fake();
        Http::preventStrayRequests();

        $this->artisan('catalog-candidates:source', [
            '--promote-item' => $item->id,
        ])->assertSuccessful();

        $this->assertNotNull($item->fresh()->product_id);
        $this->assertNotNull($item->fresh()->affiliate_link_id);
        Http::assertNothingSent();
    }

    public function test_policy_permitted_images_are_stored_without_deleting_product_on_failure(): void
    {
        Storage::fake('public');
        $home = Category::query()->where('slug', 'home-and-living')->first();
        $this->fakeSearchAndEnrichment($home->id, imageUrl: 'https://example.test/images/wallet-1.jpg');
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        app(SourceCatalogCandidatesAction::class)->execute(
            candidateId: $candidate->id,
            enrich: true,
            promote: true,
        );

        $product = Product::query()->first();
        $this->assertNotNull($product);
        $this->assertSame(1, $product->images()->count());
        $this->assertSame('BrandX French Press', $product->images()->first()->alt_text);
    }

    public function test_image_failure_does_not_delete_product_or_affiliate_link(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->first();
        $this->fakeSearchAndEnrichment(
            $home->id,
            imageUrl: 'https://example.test/images/missing.jpg',
            imageResponder: fn () => Http::response('not found', 404),
        );
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        app(SourceCatalogCandidatesAction::class)->execute(
            candidateId: $candidate->id,
            enrich: true,
            promote: true,
        );

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, AffiliateLink::query()->count());
        $this->assertSame(0, ProductImage::query()->count());
    }

    public function test_published_product_keeps_editorial_taxonomy_and_skips_image_mutations(): void
    {
        $merchant = Merchant::query()->where('slug', 'partner-a')->first();
        $home = Category::query()->where('slug', 'home-and-living')->first();
        $editorial = Category::query()->create([
            'name' => 'Editorial',
            'slug' => 'editorial-category',
            'is_active' => true,
        ]);
        $product = Product::factory()->create([
            'name' => 'Existing Gift',
            'status' => ProductStatus::Published,
        ]);
        $product->categories()->sync([
            $editorial->id => ['is_primary' => true],
        ]);
        $link = AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://partner-a.example/dp/B0ABCDEFGH?aff=test-tag',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);
        $item = CatalogCandidateSourcingItem::query()->create([
            'catalog_candidate_sourcing_run_id' => CatalogCandidateSourcingRun::factory()->create()->id,
            'catalog_candidate_id' => CatalogCandidate::factory()->create()->id,
            'merchant_id' => $merchant->id,
            'selected_offer' => [
                'merchant_id' => $merchant->id,
                'merchant_slug' => 'partner-a',
                'source_url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                'normalized_url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                'title' => 'BrandX French Press',
                'snippet' => 'French press',
                'external_product_id' => 'B0ABCDEFGH',
                'external_id_source' => 'extractor',
                'price_amount' => '1299.00',
                'price_currency' => 'INR',
                'image_urls' => ['https://example.test/images/wallet-1.jpg'],
                'retrieved_at' => now()->toIso8601String(),
                'source_evidence' => [],
                'rank_score' => 100,
            ],
            'enrichment' => [
                'catalog_candidate_id' => 1,
                'merchant_id' => $merchant->id,
                'external_product_id' => 'B0ABCDEFGH',
                'affiliate_url' => 'https://partner-a.example/dp/B0ABCDEFGH?aff=test-tag',
                'affiliate_ready' => true,
                'name' => 'BrandX French Press',
                'short_description' => 'Compact press.',
                'description' => 'A stainless steel french press.',
                'brand' => 'BrandX',
                'price_amount' => '1299.00',
                'price_currency' => 'INR',
                'primary_category_id' => $home->id,
                'taxonomy' => [
                    'category_ids' => [$home->id],
                    'occasion_ids' => [],
                    'relationship_ids' => [],
                    'recipient_type_ids' => [],
                    'interest_ids' => [],
                    'profession_ids' => [],
                    'gift_type_ids' => [],
                ],
                'image_urls' => ['https://example.test/images/wallet-1.jpg'],
                'exception_codes' => [],
                'metadata' => [],
            ],
            'status' => CatalogCandidateSourcingItemStatus::Succeeded,
        ]);

        $this->fakeCatalogImageHttp();
        app(PromoteCatalogCandidateSourcingItemAction::class)->execute($item);

        $this->assertSame($product->id, $item->fresh()->product_id);
        $this->assertSame($link->id, $item->fresh()->affiliate_link_id);
        $this->assertSame([$editorial->id], $product->fresh()->categories()->pluck('categories.id')->all());
        $this->assertSame(0, $product->fresh()->images()->count());
        $this->assertSame(ProductStatus::Published, $product->fresh()->status);
    }

    public function test_draft_product_taxonomy_can_be_updated_on_retry(): void
    {
        $merchant = Merchant::query()->where('slug', 'partner-a')->first();
        $first = Category::query()->where('slug', 'home-and-living')->first();
        $second = Category::query()->create([
            'name' => 'Kitchen',
            'slug' => 'kitchen',
            'is_active' => true,
        ]);
        $item = CatalogCandidateSourcingItem::query()->create([
            'catalog_candidate_sourcing_run_id' => CatalogCandidateSourcingRun::factory()->create()->id,
            'catalog_candidate_id' => CatalogCandidate::factory()->create()->id,
            'merchant_id' => $merchant->id,
            'selected_offer' => [
                'merchant_id' => $merchant->id,
                'merchant_slug' => 'partner-a',
                'source_url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                'normalized_url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                'title' => 'BrandX French Press',
                'snippet' => 'French press',
                'external_product_id' => 'B0ABCDEFGH',
                'external_id_source' => 'extractor',
                'price_amount' => '1299.00',
                'price_currency' => 'INR',
                'image_urls' => [],
                'retrieved_at' => now()->toIso8601String(),
                'source_evidence' => [],
                'rank_score' => 100,
            ],
            'enrichment' => [
                'catalog_candidate_id' => 1,
                'merchant_id' => $merchant->id,
                'external_product_id' => 'B0ABCDEFGH',
                'affiliate_url' => 'https://partner-a.example/dp/B0ABCDEFGH?aff=test-tag',
                'affiliate_ready' => true,
                'name' => 'BrandX French Press',
                'short_description' => 'Compact press.',
                'description' => 'A stainless steel french press.',
                'brand' => 'BrandX',
                'price_amount' => '1299.00',
                'price_currency' => 'INR',
                'primary_category_id' => $first->id,
                'taxonomy' => [
                    'category_ids' => [$first->id],
                    'occasion_ids' => [],
                    'relationship_ids' => [],
                    'recipient_type_ids' => [],
                    'interest_ids' => [],
                    'profession_ids' => [],
                    'gift_type_ids' => [],
                ],
                'image_urls' => [],
                'exception_codes' => [],
                'metadata' => [],
            ],
            'status' => CatalogCandidateSourcingItemStatus::Succeeded,
        ]);

        app(PromoteCatalogCandidateSourcingItemAction::class)->execute($item);
        $product = Product::query()->firstOrFail();
        $this->assertTrue($product->categories()->where('categories.id', $first->id)->exists());

        $item->enrichment = array_replace($item->enrichment, [
            'primary_category_id' => $second->id,
            'taxonomy' => [
                'category_ids' => [$second->id],
                'occasion_ids' => [],
                'relationship_ids' => [],
                'recipient_type_ids' => [],
                'interest_ids' => [],
                'profession_ids' => [],
                'gift_type_ids' => [],
            ],
        ]);
        $item->save();

        app(PromoteCatalogCandidateSourcingItemAction::class)->execute($item->fresh());

        $this->assertFalse($product->fresh()->categories()->where('categories.id', $first->id)->exists());
        $this->assertTrue($product->fresh()->categories()->where('categories.id', $second->id)->exists());
    }

    private function fakeSearchAndEnrichment(
        int $categoryId,
        ?int $occasionId = null,
        bool $affiliateReady = true,
        ?string $imageUrl = null,
        ?callable $imageResponder = null,
    ): void {
        $taxonomy = [
            'primary_category_id' => $categoryId,
            'category_ids' => [$categoryId],
        ];

        if ($occasionId !== null) {
            $taxonomy['occasion_ids'] = [$occasionId];
        }

        $result = [
            'title' => 'BrandX French Press',
            'url' => 'https://partner-a.example/dp/B0ABCDEFGH',
            'content' => 'BrandX French Press ₹1,299',
        ];

        if ($imageUrl !== null) {
            $result['image'] = $imageUrl;
        }

        $fakes = [
            'https://api.tavily.com/search' => Http::response([
                'results' => [$result],
            ], 200),
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => $taxonomy,
                ]),
            ),
        ];

        if ($imageUrl !== null && $imageResponder !== null) {
            $fakes[$imageUrl] = $imageResponder();
        } elseif ($imageUrl !== null) {
            $fakes[$imageUrl] = Http::response(
                (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg')),
                200,
                ['Content-Type' => 'image/jpeg'],
            );
        }

        Http::fake($fakes);

        if (! $affiliateReady) {
            config([
                'commercial_sourcing.merchants.partner-a.affiliate' => [
                    'strategy' => 'manual',
                ],
            ]);
        }
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
