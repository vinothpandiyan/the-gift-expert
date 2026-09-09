<?php

namespace Tests\Feature\CuratedCatalog;

use App\Actions\CuratedCatalog\BuildCuratedRelationshipHintFingerprintAction;
use App\Actions\CuratedCatalog\BuildCuratedTaxonomyContentFingerprintAction;
use App\Actions\CuratedCatalog\ClassifyCuratedMerchantProductAction;
use App\Actions\CuratedCatalog\EnrichCuratedMerchantProductAction;
use App\Actions\CuratedCatalog\PreviewCuratedProductIntakeAction;
use App\Actions\CuratedCatalog\RefreshCuratedMerchantProductAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\CatalogSourceListKind;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyClassificationWarningCode;
use App\Models\AffiliateLink;
use App\Models\CatalogProductSource;
use App\Models\CatalogSourceList;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\Support\FakesCommercialEnrichment;
use Tests\TestCase;

class ClassifyCuratedMerchantProductActionTest extends TestCase
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
    }

    public function test_auto_accept_applies_normalized_pivots_and_keeps_draft(): void
    {
        $fashion = $this->category('Fashion & Accessories', 'fashion-and-accessories');
        $jewellery = $this->category('Jewellery', 'jewellery', $fashion->id);
        $electronics = $this->category('Electronics', 'electronics');
        $product = $this->draftProduct();

        $this->fakeClassification($jewellery->id, [$jewellery->id, $electronics->id]);

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertTrue($result->classified);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertNull($product->published_at);
        $this->assertEqualsCanonicalizing(
            [$jewellery->id, $fashion->id],
            $product->categories()->pluck('categories.id')->all(),
        );
        $this->assertTrue((bool) $product->categories()->where('categories.id', $jewellery->id)->first()?->pivot->is_primary);
        $this->assertFalse((bool) $product->categories()->where('categories.id', $fashion->id)->first()?->pivot->is_primary);
        $this->assertFalse($product->categories()->where('categories.id', $electronics->id)->exists());
        $this->assertNotNull($product->taxonomy_classification_proposal);
        $this->assertSame('BrandX French Press', $product->name);
    }

    public function test_review_persists_proposal_without_applying_pivots(): void
    {
        $home = $this->category('Home & Living', 'home-and-living');
        $product = $this->draftProduct();

        $this->fakeClassification($home->id, [$home->id], confidence: 0.70);

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertSame(TaxonomyClassificationStatus::Review, $product->taxonomy_classification_status);
        $this->assertContains(
            TaxonomyClassificationWarningCode::LowPrimaryCategoryConfidence->value,
            $product->taxonomy_review_reasons,
        );
        $this->assertSame(0, $product->categories()->count());
        $this->assertSame('Gift B0ABCDEFGH', $product->name);
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertNotNull($product->taxonomy_classification_proposal);
        $this->assertSame($home->id, $result->proposal?->taxonomy->primaryCategoryId);
    }

    public function test_advisory_taxonomy_gap_auto_accepts_and_persists_warning(): void
    {
        $toys = $this->category('Toys & Games', 'toys-and-games');
        $product = $this->draftProduct();
        $categoryCount = Category::query()->count();

        $this->fakeClassification($toys->id, [$toys->id], confidence: 0.98, gap: [
            'detected' => true,
            'severity' => 'advisory',
            'suggested_concept' => 'Diecast Models',
            'explanation' => 'A more specific leaf would help later.',
        ]);

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertContains(
            TaxonomyClassificationWarningCode::TaxonomyGapAdvisory->value,
            $product->taxonomy_classification_warnings ?? [],
        );
        $this->assertSame([], $product->taxonomy_review_reasons ?? []);
        $this->assertSame('Diecast Models', $product->taxonomy_gap_suggestion);
        $this->assertTrue($product->categories()->where('categories.id', $toys->id)->exists());
        $this->assertSame($categoryCount, Category::query()->count());
        $this->assertFalse(Category::query()->where('name', 'Diecast Models')->exists());
        $this->assertSame('advisory', $result->proposal?->taxonomyGap->severity?->value);
    }

    public function test_blocking_taxonomy_gap_goes_to_review_without_applying_pivots(): void
    {
        $home = $this->category('Home & Living', 'home-and-living');
        $product = $this->draftProduct();

        $this->fakeClassification($home->id, [$home->id], confidence: 0.90, gap: [
            'detected' => true,
            'severity' => 'blocking',
            'suggested_concept' => 'Unknown family',
            'explanation' => 'No honest merchandising category.',
        ]);

        app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertSame(TaxonomyClassificationStatus::Review, $product->taxonomy_classification_status);
        $this->assertContains(
            TaxonomyClassificationWarningCode::TaxonomyGapBlocking->value,
            $product->taxonomy_review_reasons ?? [],
        );
        $this->assertSame(0, $product->categories()->count());
        $this->assertSame('Unknown family', $product->taxonomy_gap_suggestion);
        $this->assertSame('Gift B0ABCDEFGH', $product->name);
    }

    public function test_failed_missing_primary_retains_structured_response(): void
    {
        $this->category('Home & Living', 'home-and-living');
        $product = $this->draftProduct();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => null,
                        'category_ids' => [],
                        'relationship_ids' => [],
                    ],
                    'confidence' => [
                        'primary_category' => 0.18,
                    ],
                    'reasoning_summary' => [
                        'primary_category' => 'Title names a combo gift without a product family.',
                        'relationships' => 'Wife hint is present but identity is unclear.',
                    ],
                    'taxonomy_gap' => [
                        'detected' => true,
                        'severity' => 'blocking',
                        'suggested_concept' => null,
                        'explanation' => 'Product identity is too ambiguous for merchandising.',
                    ],
                ]),
            ),
        ]);

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertSame(TaxonomyClassificationStatus::Failed, $result->status);
        $this->assertSame(0, $product->categories()->count());
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertSame('Gift B0ABCDEFGH', $product->name);
        $this->assertContains(
            TaxonomyClassificationWarningCode::MissingPrimaryCategory->value,
            $product->taxonomy_review_reasons ?? [],
        );

        $proposal = $product->taxonomy_classification_proposal ?? [];
        $this->assertArrayHasKey('primary_category_id', $proposal);
        $this->assertNull($proposal['primary_category_id']);
        $this->assertSame(0.18, $proposal['confidence']['primary_category'] ?? null);
        $this->assertSame(
            'Title names a combo gift without a product family.',
            $proposal['reasoning_summary']['primary_category'] ?? null,
        );
        $this->assertSame('blocking', $proposal['taxonomy_gap']['severity'] ?? null);
        $this->assertContains('missing_primary_category', $proposal['exception_codes'] ?? []);
        $this->assertArrayHasKey('structured_response', $proposal);
        $this->assertArrayHasKey('primary_category_id', $proposal['structured_response']);
        $this->assertNull($proposal['structured_response']['primary_category_id']);
        $this->assertSame(
            'Product identity is too ambiguous for merchandising.',
            $product->taxonomy_gap_explanation,
        );
        $this->assertSame(
            'Title names a combo gift without a product family.',
            $product->taxonomy_reasoning['primary_category'] ?? null,
        );
    }

    public function test_failed_missing_primary_does_not_apply_pivots(): void
    {
        $this->category('Home & Living', 'home-and-living');
        $product = $this->draftProduct();

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

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertSame(TaxonomyClassificationStatus::Failed, $result->status);
        $this->assertSame(0, $product->categories()->count());
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertSame('Gift B0ABCDEFGH', $product->name);
    }

    public function test_human_approved_is_not_overwritten(): void
    {
        $home = $this->category('Home & Living', 'home-and-living');
        $product = $this->draftProduct();
        $product->taxonomy_classification_status = TaxonomyClassificationStatus::HumanApproved;
        $product->save();
        $product->categories()->attach($home->id, ['is_primary' => true]);

        $this->mock(EnrichCuratedMerchantProductAction::class, function ($mock): void {
            $mock->shouldNotReceive('execute');
        });

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product->fresh());

        $this->assertFalse($result->classified);
        $this->assertSame('human_locked', $result->reason);
        $this->assertSame(TaxonomyClassificationStatus::HumanApproved, $product->fresh()->taxonomy_classification_status);
        $this->assertTrue($product->fresh()->categories()->where('categories.id', $home->id)->exists());
    }

    public function test_price_refresh_does_not_call_enrichment(): void
    {
        $home = $this->category('Home & Living', 'home-and-living');
        $product = $this->draftProduct();
        $product->taxonomy_classification_status = TaxonomyClassificationStatus::AiAccepted;
        $product->taxonomy_classification_version = 1;
        $product->price_amount = '100.00';
        $product->save();
        $product->categories()->attach($home->id, ['is_primary' => true]);
        $product->taxonomy_content_fingerprint = app(BuildCuratedTaxonomyContentFingerprintAction::class)->execute($product);
        $product->taxonomy_relationship_hint_fingerprint = app(BuildCuratedRelationshipHintFingerprintAction::class)->execute($product);
        $product->save();

        $this->mock(EnrichCuratedMerchantProductAction::class, function ($mock): void {
            $mock->shouldNotReceive('execute');
        });

        $input = app(PreviewCuratedProductIntakeAction::class)->execute($this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0ABCDEFGH',
                'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                'title' => 'New marketplace title',
                'price_amount' => '1499.00',
                'price_currency' => 'INR',
            ]],
        ]))->items[0]->input;

        app(RefreshCuratedMerchantProductAction::class)->execute($this->merchant, $input);

        $product->refresh();
        $this->assertSame('1499.00', $product->price_amount);
        $this->assertSame('Gift B0ABCDEFGH', $product->name);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertTrue($product->categories()->where('categories.id', $home->id)->exists());
    }

    public function test_trusted_hints_are_sent_in_one_enrichment_call(): void
    {
        $home = $this->category('Home & Living', 'home-and-living');
        $product = $this->draftProduct();
        foreach (['Husband', 'Boyfriend', 'Brother'] as $name) {
            $relationship = Relationship::query()->create([
                'name' => $name,
                'slug' => str($name)->slug()->toString(),
                'is_active' => true,
            ]);
            $this->attachList($product, $relationship, 'Gifts for '.$name, CatalogSourceListKind::RecipientHint);
        }
        $this->attachList($product, null, '01 - Gift Ideas - Q1 2026', CatalogSourceListKind::QuarterlyArchive);
        $this->attachList($product, null, '00 - Unclassified Gift Ideas', CatalogSourceListKind::UnclassifiedInbox);

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

        app(ClassifyCuratedMerchantProductAction::class)->execute($product->fresh());

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }

            $user = $request['messages'][1]['content'] ?? '';

            return str_contains((string) $user, 'Husband')
                && str_contains((string) $user, 'Boyfriend')
                && str_contains((string) $user, 'Brother')
                && ! str_contains((string) $user, 'Q1')
                && ! str_contains((string) $user, 'Unclassified');
        });
    }

    private function draftProduct(): Product
    {
        $product = Product::factory()->create([
            'name' => 'Gift B0ABCDEFGH',
            'status' => ProductStatus::Draft,
            'taxonomy_classification_status' => TaxonomyClassificationStatus::None,
            'price_amount' => '1299.00',
            'price_currency' => 'INR',
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $this->merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH?tag=test-tag-20',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

        return $product->fresh(['affiliateLinks.merchant']);
    }

    /**
     * @param  list<int>  $categoryIds
     * @param  array<string, mixed>|null  $gap
     */
    private function fakeClassification(int $primaryId, array $categoryIds, float $confidence = 0.95, ?array $gap = null): void
    {
        $fields = [
            'taxonomy' => [
                'primary_category_id' => $primaryId,
                'category_ids' => $categoryIds,
            ],
            'confidence' => [
                'primary_category' => $confidence,
            ],
        ];

        if (is_array($gap)) {
            $fields['taxonomy_gap'] = $gap;
        }

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion($fields),
            ),
        ]);
    }

    private function category(string $name, string $slug, ?int $parentId = null): Category
    {
        return Category::query()->create([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function attachList(
        Product $product,
        ?Relationship $relationship,
        string $name,
        CatalogSourceListKind $kind,
    ): void {
        $list = CatalogSourceList::query()->create([
            'merchant_id' => $this->merchant->id,
            'name' => $name,
            'normalized_name' => str($name)->slug()->toString(),
            'kind' => $kind,
            'relationship_id' => $relationship?->id,
            'is_active' => true,
        ]);
        CatalogProductSource::query()->create([
            'affiliate_link_id' => $product->affiliateLinks()->first()->id,
            'catalog_source_list_id' => $list->id,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'occurrence_count' => 1,
        ]);
    }
}
