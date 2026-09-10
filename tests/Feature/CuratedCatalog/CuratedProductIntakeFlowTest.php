<?php

namespace Tests\Feature\CuratedCatalog;

use App\Actions\CuratedCatalog\CreateCuratedMerchantProductAction;
use App\Actions\CuratedCatalog\PreviewCuratedProductIntakeAction;
use App\Actions\CuratedCatalog\RefreshCuratedMerchantProductAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateIngestionRun;
use App\Models\CatalogCandidateSourcingRun;
use App\Models\Category;
use App\Models\CuratedProductIntakeItem;
use App\Models\CuratedProductIntakeRun;
use App\Models\ImportRun;
use App\Models\Merchant;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\Support\FakesCommercialEnrichment;
use Tests\Support\FakesCuratedProductImages;
use Tests\Support\ProcessesCuratedSyncRuns;
use Tests\TestCase;

class CuratedProductIntakeFlowTest extends TestCase
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
            'Husband',
            'Boyfriend',
            'Father',
            'Brother',
            'Son',
            'Wife',
            'Girlfriend',
            'Mother',
            'Sister',
            'Daughter',
            'Friends',
            'Colleagues',
        ] as $sortOrder => $name) {
            Relationship::query()->create([
                'name' => $name,
                'slug' => str($name)->slug()->toString(),
                'sort_order' => $sortOrder + 1,
                'is_active' => true,
            ]);
        }
    }

    public function test_preview_performs_zero_writes_and_zero_ai(): void
    {
        Http::fake();

        $before = $this->counts();

        $preview = app(PreviewCuratedProductIntakeAction::class)->execute($this->curatedPayload());

        $this->assertSame(1, $preview->itemsNew);
        $this->assertSame('CREATE', $preview->items[0]->proposedAction);
        $this->assertTrue($preview->items[0]->affiliateReady);
        $this->assertSame($before, $this->counts());
        Http::assertNothingSent();
    }

    public function test_preview_marks_existing_and_trashed_identities(): void
    {
        $existing = Product::query()->create([
            'name' => 'Existing Gift',
            'slug' => 'existing-gift',
            'status' => ProductStatus::Draft,
            'price_amount' => '100.00',
            'price_currency' => 'INR',
        ]);
        AffiliateLink::query()->create([
            'product_id' => $existing->id,
            'merchant_id' => $this->merchant->id,
            'url' => 'https://www.amazon.in/dp/B0EXISTIN1?tag=old',
            'external_product_id' => 'B0EXISTIN1',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

        $trashed = Product::query()->create([
            'name' => 'Trashed Gift',
            'slug' => 'trashed-gift',
            'status' => ProductStatus::Draft,
            'price_amount' => '100.00',
            'price_currency' => 'INR',
        ]);
        $trashedLink = AffiliateLink::query()->create([
            'product_id' => $trashed->id,
            'merchant_id' => $this->merchant->id,
            'url' => 'https://www.amazon.in/dp/B0TRASHED1?tag=old',
            'external_product_id' => 'B0TRASHED1',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);
        $trashedLink->delete();
        $trashed->delete();

        $preview = app(PreviewCuratedProductIntakeAction::class)->execute($this->curatedPayload([
            'items' => [
                [
                    'external_product_id' => 'B0EXISTIN1',
                    'source_url' => 'https://www.amazon.in/dp/B0EXISTIN1',
                    'title' => 'Existing',
                    'price_amount' => '499.00',
                    'price_currency' => 'INR',
                ],
                [
                    'external_product_id' => 'B0TRASHED1',
                    'source_url' => 'https://www.amazon.in/dp/B0TRASHED1',
                    'title' => 'Trashed',
                ],
            ],
        ]));

        $this->assertSame('UPDATE', $preview->items[0]->proposedAction);
        $this->assertSame('SKIP', $preview->items[1]->proposedAction);
        $this->assertSame('trashed', $preview->items[1]->disposition);
    }

    public function test_create_builds_draft_product_with_server_side_affiliate_url_and_taxonomy(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                    ],
                ]),
            ),
            'https://m.media-amazon.com/images/*' => Http::response(
                (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg')),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);
        Storage::fake('public');

        $input = app(PreviewCuratedProductIntakeAction::class)->execute($this->curatedPayload())->items[0]->input;
        $result = app(CreateCuratedMerchantProductAction::class)->execute($this->merchant, $input);

        $this->assertTrue($result->success);
        $product = Product::query()->first();
        $link = AffiliateLink::query()->first();

        $this->assertNotNull($product);
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertNull($product->published_at);
        $this->assertSame('BrandX French Press', $product->name);
        $this->assertStringContainsString('tag=test-tag-20', $link->url);
        $this->assertTrue($product->categories()->where('categories.id', $home->id)->exists());
        $this->assertSame(1, ProductImage::query()->count());
        $this->assertSame(
            'https://m.media-amazon.com/images/I/example._SL1500_.jpg',
            ProductImage::query()->first()->source_url,
        );
        Http::assertSentCount(2);
    }

    public function test_create_persists_broad_curated_relationship_eligibility_without_shared_cap_truncation(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $relationshipIds = Relationship::query()->orderBy('sort_order')->pluck('id')->all();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'name' => 'Digital Portable Alarm Clock for Desk',
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                        'relationship_ids' => $relationshipIds,
                    ],
                ]),
            ),
        ]);

        $payload = $this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0ALARM001',
                'source_url' => 'https://www.amazon.in/dp/B0ALARM001',
                'title' => 'Digital Portable Alarm Clock for Desk',
            ]],
        ]);
        $input = app(PreviewCuratedProductIntakeAction::class)->execute($payload)->items[0]->input;
        $result = app(CreateCuratedMerchantProductAction::class)->execute($this->merchant, $input);

        $this->assertTrue($result->success);
        $this->assertSame(12, Product::query()->firstOrFail()->relationships()->count());
        $this->assertTrue(Product::query()->firstOrFail()->relationships()->where('slug', 'wife')->exists());
        $this->assertTrue(Product::query()->firstOrFail()->relationships()->where('slug', 'mother')->exists());
        $this->assertNotContains('taxonomy_ids_rejected', $result->warnings);
    }

    public function test_create_persists_failed_classification_without_invalid_pivots_when_primary_is_missing(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => null,
                        'category_ids' => [],
                    ],
                ]),
            ),
            'https://m.media-amazon.com/images/*' => Http::response(
                (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg')),
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);
        Storage::fake('public');

        $input = app(PreviewCuratedProductIntakeAction::class)->execute($this->curatedPayload())->items[0]->input;
        $result = app(CreateCuratedMerchantProductAction::class)->execute($this->merchant, $input);

        $this->assertTrue($result->success);
        $product = Product::query()->first();
        $this->assertNotNull($product);
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertSame(TaxonomyClassificationStatus::Failed, $product->taxonomy_classification_status);
        $this->assertSame(0, $product->categories()->count());
        $this->assertContains('classification_failed', $result->warnings);
    }

    public function test_create_allows_rejected_optional_taxonomy_ids_with_warning(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                        'occasion_ids' => [999999],
                    ],
                ]),
            ),
        ]);

        $input = app(PreviewCuratedProductIntakeAction::class)->execute($this->curatedPayload())->items[0]->input;
        $result = app(CreateCuratedMerchantProductAction::class)->execute($this->merchant, $input);

        $this->assertTrue($result->success);
        $this->assertContains('taxonomy_ids_rejected', $result->warnings);
        $this->assertSame(0, Product::query()->first()->occasions()->count());
    }

    public function test_refresh_updates_price_without_touching_editorial_fields_or_taxonomy(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $birthday = Occasion::query()->where('slug', 'birthday')->firstOrFail();

        $product = Product::query()->create([
            'name' => 'Editorial Name',
            'slug' => 'editorial-name',
            'short_description' => 'Short',
            'description' => 'Long',
            'brand' => 'Brand',
            'status' => ProductStatus::Published,
            'price_amount' => '100.00',
            'price_currency' => 'INR',
            'published_at' => now(),
        ]);
        $product->categories()->attach($home->id, ['is_primary' => true]);
        $product->occasions()->attach($birthday->id);

        $link = AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $this->merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH?tag=old',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

        Http::fake();

        $input = app(PreviewCuratedProductIntakeAction::class)->execute($this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0ABCDEFGH',
                'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                'title' => 'Stainless Steel French Press',
                'price_amount' => '1299.00',
                'price_currency' => 'INR',
                'availability' => 'in_stock',
            ]],
        ]))->items[0]->input;
        $result = app(RefreshCuratedMerchantProductAction::class)->execute($this->merchant, $input);

        $product->refresh();
        $link->refresh();

        $this->assertTrue($result->success);
        $this->assertSame('1299.00', $product->price_amount);
        $this->assertSame('Editorial Name', $product->name);
        $this->assertSame('Short', $product->short_description);
        $this->assertStringContainsString('tag=test-tag-20', $link->url);
        $this->assertTrue($product->occasions()->where('occasions.id', $birthday->id)->exists());
        Http::assertNothingSent();
    }

    public function test_refresh_preserves_price_when_incoming_price_missing(): void
    {
        $product = Product::query()->create([
            'name' => 'Editorial Name',
            'slug' => 'editorial-name-2',
            'status' => ProductStatus::Draft,
            'price_amount' => '100.00',
            'price_currency' => 'INR',
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $this->merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH?tag=old',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

        $input = app(PreviewCuratedProductIntakeAction::class)->execute($this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0ABCDEFGH',
                'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                'title' => 'Updated title from browser',
            ]],
        ]))->items[0]->input;

        app(RefreshCuratedMerchantProductAction::class)->execute($this->merchant, $input);

        $this->assertSame('100.00', $product->fresh()->price_amount);
        $this->assertSame('Editorial Name', $product->fresh()->name);
    }

    public function test_refresh_skips_archived_products(): void
    {
        $product = Product::query()->create([
            'name' => 'Archived Gift',
            'slug' => 'archived-gift',
            'status' => ProductStatus::Archived,
            'price_amount' => '100.00',
            'price_currency' => 'INR',
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $this->merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH?tag=old',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

        $input = app(PreviewCuratedProductIntakeAction::class)->execute($this->curatedPayload())->items[0]->input;
        $result = app(RefreshCuratedMerchantProductAction::class)->execute($this->merchant, $input);

        $this->assertSame('skipped', $result->outcome);
        $this->assertSame('100.00', $product->fresh()->price_amount);
    }

    public function test_refresh_does_not_restore_soft_deleted_identity(): void
    {
        $product = Product::query()->create([
            'name' => 'Trashed Gift',
            'slug' => 'trashed-gift-2',
            'status' => ProductStatus::Draft,
            'price_amount' => '100.00',
            'price_currency' => 'INR',
        ]);
        $link = AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $this->merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH?tag=old',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);
        $link->delete();
        $product->delete();

        $input = app(PreviewCuratedProductIntakeAction::class)->execute($this->curatedPayload())->items[0]->input;
        $result = app(RefreshCuratedMerchantProductAction::class)->execute($this->merchant, $input);

        $this->assertSame('skipped', $result->outcome);
        $this->assertSame('trashed_identity', $result->error);
        $this->assertSame(1, AffiliateLink::onlyTrashed()->count());
    }

    public function test_process_sync_creates_audit_rows_for_all_items(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();

        $items = [];

        for ($i = 1; $i <= 2; $i++) {
            $asin = 'B00000000'.$i;
            $items[] = [
                'external_product_id' => $asin,
                'source_url' => 'https://www.amazon.in/dp/'.$asin,
                'title' => 'Gift '.$i,
                'price_amount' => '499.00',
                'price_currency' => 'INR',
                'source_image_url' => 'https://m.media-amazon.com/images/I/'.$asin.'.jpg',
            ];
        }

        $imageBody = (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg'));

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push($this->commercialEnrichmentCompletion([
                    'name' => 'Gift One',
                    'taxonomy' => ['primary_category_id' => $home->id, 'category_ids' => [$home->id]],
                ]))
                ->push($this->commercialEnrichmentCompletion([
                    'name' => 'Gift Two',
                    'taxonomy' => ['primary_category_id' => $home->id, 'category_ids' => [$home->id]],
                ])),
            'https://m.media-amazon.com/images/I/*' => Http::response(
                $imageBody,
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);
        Storage::fake('public');

        $result = $this->runCuratedSync($this->curatedPayload([
            'items' => $items,
        ]));

        $this->assertSame(2, $result->itemsProcessed);
        $this->assertSame(2, $result->itemsCreated);
        $this->assertSame(1, CuratedProductIntakeRun::query()->count());
        $this->assertSame(2, CuratedProductIntakeItem::query()->count());
        $this->assertSame(2, Product::query()->count());
    }

    public function test_second_import_updates_existing_product(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $imageBody = (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg'));

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                    ],
                ]),
            ),
            'https://m.media-amazon.com/images/I/*' => Http::response(
                $imageBody,
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);
        Storage::fake('public');

        $this->runCuratedSync($this->curatedPayload());

        Http::fake();

        $result = $this->runCuratedSync($this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0ABCDEFGH',
                'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                'title' => 'Browser title should not overwrite',
                'price_amount' => '1499.00',
                'price_currency' => 'INR',
                'source_image_url' => 'https://m.media-amazon.com/images/I/example.jpg',
            ]],
        ]));

        $this->assertSame(1, $result->itemsUpdated);
        $this->assertSame('1499.00', Product::query()->first()->price_amount);
        $this->assertSame('BrandX French Press', Product::query()->first()->name);
        Http::assertNothingSent();
    }

    public function test_isolation_from_candidate_sourcing_and_import_runs(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $imageBody = (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg'));

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                    ],
                ]),
            ),
            'https://m.media-amazon.com/images/I/*' => Http::response(
                $imageBody,
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);
        Storage::fake('public');

        $this->runCuratedSync($this->curatedPayload());

        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        $this->assertSame(0, CatalogCandidateSourcingRun::query()->count());
        $this->assertSame(0, ImportRun::query()->count());
        $this->assertSame(14, $this->discoveryRouteCount());
    }

    private function fakeEnrichment(int $categoryId): void
    {
        Http::fake([
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

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'products' => Product::query()->count(),
            'links' => AffiliateLink::query()->count(),
            'runs' => CuratedProductIntakeRun::query()->count(),
        ];
    }

    private function discoveryRouteCount(): int
    {
        return collect(Route::getRoutes())->filter(
            fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'),
        )->count();
    }
}
