<?php

namespace Tests\Feature\CuratedCatalog;

use App\Actions\CuratedCatalog\CreateCuratedMerchantProductAction;
use App\Actions\CuratedCatalog\PreviewCuratedProductIntakeAction;
use App\Actions\CuratedCatalog\ProcessCuratedProductIntakeAction;
use App\Actions\CuratedCatalog\RefreshCuratedMerchantProductAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\Support\FakesCommercialEnrichment;
use Tests\TestCase;

class CuratedProductIntakeFlowTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use FakesCommercialEnrichment;
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
        $this->fakeEnrichment($home->id);

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
        $this->assertSame(0, ProductImage::query()->count());
        Http::assertSentCount(1);
    }

    public function test_create_fails_before_product_write_when_primary_category_missing(): void
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
        ]);

        $input = app(PreviewCuratedProductIntakeAction::class)->execute($this->curatedPayload())->items[0]->input;
        $result = app(CreateCuratedMerchantProductAction::class)->execute($this->merchant, $input);

        $this->assertFalse($result->success);
        $this->assertSame(0, Product::query()->count());
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

        $input = app(PreviewCuratedProductIntakeAction::class)->execute($this->curatedPayload())->items[0]->input;
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

    public function test_process_import_creates_audit_rows_and_enforces_commit_limit(): void
    {
        config(['curated_catalog.max_items_per_commit' => 1]);
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();

        $items = [];

        for ($i = 1; $i <= 2; $i++) {
            $asin = 'B0NEW0000'.$i;
            $items[] = [
                'external_product_id' => $asin,
                'source_url' => 'https://www.amazon.in/dp/'.$asin,
                'title' => 'Gift '.$i,
                'price_amount' => '499.00',
                'price_currency' => 'INR',
            ];
        }

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
        ]);

        $result = app(ProcessCuratedProductIntakeAction::class)->execute($this->curatedPayload([
            'items' => $items,
        ]));

        $this->assertSame(1, $result->itemsProcessed);
        $this->assertSame(1, $result->itemsCreated);
        $this->assertSame(1, $result->itemsRemaining);
        $this->assertSame(1, CuratedProductIntakeRun::query()->count());
        $this->assertSame(2, CuratedProductIntakeItem::query()->count());
        $this->assertSame(1, Product::query()->count());
    }

    public function test_second_import_updates_existing_product(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $this->fakeEnrichment($home->id);

        app(ProcessCuratedProductIntakeAction::class)->execute($this->curatedPayload());

        Http::fake();

        $result = app(ProcessCuratedProductIntakeAction::class)->execute($this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0ABCDEFGH',
                'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                'title' => 'Browser title should not overwrite',
                'price_amount' => '1499.00',
                'price_currency' => 'INR',
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
        $this->fakeEnrichment($home->id);

        app(ProcessCuratedProductIntakeAction::class)->execute($this->curatedPayload());

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
