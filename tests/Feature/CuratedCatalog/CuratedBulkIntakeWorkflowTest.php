<?php

namespace Tests\Feature\CuratedCatalog;

use App\Enums\CatalogSourceListKind;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogProductSource;
use App\Models\CatalogSourceList;
use App\Models\Category;
use App\Models\CuratedProductIntakeRun;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\Support\FakesCommercialEnrichment;
use Tests\Support\FakesCuratedProductImages;
use Tests\TestCase;

class CuratedBulkIntakeWorkflowTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use FakesCommercialEnrichment;
    use FakesCuratedProductImages;
    use RefreshDatabase;

    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureCuratedAmazonMerchant();
        $this->seedCuratedRelationships();
        $this->fixtures = base_path('tests/Fixtures/curated-catalog/initial-bulk');
    }

    public function test_directory_dry_run_deferred_ingest_classification_and_audit(): void
    {
        $this->artisan('catalog:curated-intake', [
            'path' => $this->fixtures,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Gifts for Husband')
            ->expectsOutputToContain('recipient_hint')
            ->expectsOutputToContain('relationship: Husband')
            ->expectsOutputToContain('00 - Unclassified Gift Ideas')
            ->expectsOutputToContain('unclassified_inbox')
            ->expectsOutputToContain('01 - Gift Ideas - Q1 2026')
            ->expectsOutputToContain('quarterly_archive')
            ->expectsOutputToContain('Unique merchant products')
            ->expectsOutputToContain('Dry run completed')
            ->assertSuccessful();

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, CuratedProductIntakeRun::query()->count());

        $this->fakeImageHttp();

        $this->artisan('catalog:curated-intake', [
            'path' => $this->fixtures,
            '--defer-classification' => true,
        ])
            ->expectsOutputToContain('Intake run')
            ->expectsOutputToContain('New products: 5')
            ->assertSuccessful();

        $this->assertSame(5, Product::query()->count());
        $this->assertSame(5, AffiliateLink::query()->count());
        $this->assertSame(5, CatalogSourceList::query()->count());
        $this->assertSame(9, CatalogProductSource::query()->count());

        $shared = $this->productByAsin('B0SHARED01');
        $this->assertSame(5, $shared->affiliateLinks->first()->catalogProductSources()->count());
        $this->assertSame(ProductStatus::Draft, $shared->status);
        $this->assertSame(TaxonomyClassificationStatus::None, $shared->taxonomy_classification_status);
        $this->assertSame(0, $shared->relationships()->count());

        $quarterly = CatalogSourceList::query()
            ->where('kind', CatalogSourceListKind::QuarterlyArchive)
            ->first();
        $this->assertNotNull($quarterly);
        $this->assertNull($quarterly->relationship_id);

        $inbox = CatalogSourceList::query()
            ->where('kind', CatalogSourceListKind::UnclassifiedInbox)
            ->first();
        $this->assertNotNull($inbox);
        $this->assertNull($inbox->relationship_id);

        $run = CuratedProductIntakeRun::query()->firstOrFail();
        $outsider = Product::query()->create([
            'name' => 'Outside Gift',
            'slug' => 'outside-gift',
            'status' => ProductStatus::Draft->value,
            'price_amount' => '100.00',
            'price_currency' => 'INR',
        ]);
        AffiliateLink::query()->create([
            'product_id' => $outsider->id,
            'merchant_id' => $run->merchant_id,
            'url' => 'https://www.amazon.in/dp/B0OUTSIDE1?tag=test-tag-20',
            'external_product_id' => 'B0OUTSIDE1',
            'is_primary' => true,
            'status' => 'active',
        ]);
        $outsider->taxonomy_classification_status = TaxonomyClassificationStatus::None;
        $outsider->save();

        $fashion = Category::query()->create([
            'name' => 'Fashion & Accessories',
            'slug' => 'fashion-and-accessories',
            'is_active' => true,
        ]);
        $jewellery = Category::query()->create([
            'name' => 'Jewellery',
            'slug' => 'jewellery',
            'parent_id' => $fashion->id,
            'is_active' => true,
        ]);

        $this->artisan('catalog:classify-curated', [
            '--intake-run' => (string) $run->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Unique Products requiring AI classification')
            ->expectsOutputToContain('Dry run completed')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'openai'));

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $jewellery->id,
                        'category_ids' => [$jewellery->id],
                    ],
                    'confidence' => [
                        'primary_category' => 0.95,
                    ],
                ]),
            ),
        ]);

        $this->artisan('catalog:classify-curated', [
            '--intake-run' => (string) $run->id,
            '--limit' => '2',
        ])->assertSuccessful();

        Http::assertSentCount(2);

        $this->artisan('catalog:classify-curated', [
            '--intake-run' => (string) $run->id,
        ])
            ->expectsOutputToContain('AI Accepted:')
            ->expectsOutputToContain('Needs Review:')
            ->expectsOutputToContain('/admin/gifts?activeTab=failed')
            ->assertSuccessful();

        $this->assertSame(5, Http::recorded(fn ($request): bool => str_contains($request->url(), 'chat/completions'))->count());

        $this->artisan('catalog:classify-curated', [
            '--intake-run' => (string) $run->id,
        ])->assertSuccessful();

        $this->assertSame(5, Http::recorded(fn ($request): bool => str_contains($request->url(), 'chat/completions'))->count());

        $this->assertSame(
            TaxonomyClassificationStatus::None,
            $outsider->fresh()->taxonomy_classification_status,
        );

        foreach (Product::query()->whereIn('id', $run->items()->pluck('product_id'))->get() as $product) {
            $this->assertSame(ProductStatus::Draft, $product->status);
            $this->assertNull($product->published_at);
        }

        $shared->refresh();
        $this->assertTrue($shared->relationships()->where('slug', 'husband')->exists());
        $this->assertTrue($shared->relationships()->where('slug', 'boyfriend')->exists());
        $this->assertTrue($shared->relationships()->where('slug', 'brother')->exists());
        $this->assertTrue($shared->categories()->where('categories.id', $jewellery->id)->wherePivot('is_primary', true)->exists());
        $this->assertTrue($shared->categories()->where('categories.id', $fashion->id)->exists());

        $inboxProduct = $this->productByAsin('B0INBOX001');
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $inboxProduct->taxonomy_classification_status);
        $this->assertSame(0, $inboxProduct->relationships()->count());

        $this->artisan('catalog:curated-audit', [
            '--intake-run' => (string) $run->id,
        ])
            ->expectsOutputToContain('Child primary Category missing active ancestor: 0')
            ->expectsOutputToContain('Applied semantic conflicts: 0')
            ->expectsOutputToContain('Spot-check sample')
            ->assertSuccessful();
    }

    public function test_reimport_does_not_duplicate_or_call_ai(): void
    {
        $this->fakeImageHttp();

        $this->artisan('catalog:curated-intake', [
            'path' => $this->fixtures,
            '--defer-classification' => true,
        ])->assertSuccessful();

        $firstSeen = CatalogProductSource::query()->orderBy('id')->pluck('first_seen_at', 'id');
        $this->travel(1)->hours();
        $this->fakeImageHttp();

        $this->artisan('catalog:curated-intake', [
            'path' => $this->fixtures,
            '--defer-classification' => true,
        ])->assertSuccessful();

        $this->assertSame(5, Product::query()->count());
        $this->assertSame(5, AffiliateLink::query()->count());
        $this->assertSame(5, CatalogSourceList::query()->count());
        $this->assertSame(9, CatalogProductSource::query()->count());
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'openai'));

        foreach (CatalogProductSource::query()->get() as $source) {
            $this->assertTrue($source->first_seen_at->equalTo($firstSeen[$source->id]));
            $this->assertTrue($source->last_seen_at->greaterThan($source->first_seen_at));
            $this->assertSame(2, $source->occurrence_count);
        }
    }

    private function productByAsin(string $asin): Product
    {
        $link = AffiliateLink::query()->where('external_product_id', $asin)->firstOrFail();

        return $link->product()->firstOrFail()->load('affiliateLinks');
    }

    private function fakeImageHttp(): void
    {
        Storage::fake('public');
        $body = (string) file_get_contents($this->rasterImagePath(640, 640, 'jpeg'));

        Http::fake([
            'https://m.media-amazon.com/images/I/*' => Http::response($body, 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }
}
