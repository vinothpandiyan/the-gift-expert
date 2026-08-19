<?php

namespace Tests\Unit\Actions\Product;

use App\Actions\Product\EvaluateAndPersistProductAutomationReadinessAction;
use App\Actions\Product\EvaluateProductAutomationReadinessAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\CatalogCandidateSourcingItemStatus;
use App\Enums\ProductAutomationReadiness;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateSourcingItem;
use App\Models\CatalogCandidateSourcingRun;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConfiguresCommercialSourcing;
use Tests\TestCase;

class EvaluateProductAutomationReadinessActionTest extends TestCase
{
    use ConfiguresCommercialSourcing;
    use RefreshDatabase;

    public function test_healthy_draft_product_is_ready(): void
    {
        [$product, $item] = $this->promotedDraftPair();

        $result = app(EvaluateProductAutomationReadinessAction::class)->execute($item->fresh());

        $this->assertSame(ProductAutomationReadiness::Ready, $result->readiness);
        $this->assertSame([], $result->exceptionCodes);
    }

    public function test_missing_image_blocks_with_no_image_code(): void
    {
        [$product, $item] = $this->promotedDraftPair(withImage: false);

        $result = app(EvaluateProductAutomationReadinessAction::class)->execute($item->fresh());

        $this->assertSame(ProductAutomationReadiness::Blocked, $result->readiness);
        $this->assertContains('no_image', $result->exceptionCodes);
    }

    public function test_image_policy_is_explanatory_when_images_missing(): void
    {
        $merchant = $this->createActiveMerchant('partner-a');
        $this->useCommercialMerchants([
            'partner-a' => $this->commercialMerchantConfig('partner-a', [
                'image_policy_key' => 'amazon_associates',
            ]),
        ]);

        [$product, $item] = $this->promotedDraftPair(
            merchant: $merchant,
            withImage: false,
            enrichmentOverrides: [
                'image_urls' => ['https://example.test/images/product.jpg'],
            ],
        );

        $result = app(EvaluateProductAutomationReadinessAction::class)->execute($item->fresh());

        $this->assertSame(ProductAutomationReadiness::Blocked, $result->readiness);
        $this->assertContains('no_image', $result->exceptionCodes);
        $this->assertContains('image_policy', $result->exceptionCodes);
    }

    public function test_manual_image_upload_clears_image_codes_on_reevaluation(): void
    {
        [$product, $item] = $this->promotedDraftPair(withImage: false);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'images/manual.jpg',
            'is_primary' => true,
        ]);

        $result = app(EvaluateProductAutomationReadinessAction::class)->execute($item->fresh());

        $this->assertNotContains('no_image', $result->exceptionCodes);
        $this->assertNotContains('image_policy', $result->exceptionCodes);
    }

    public function test_missing_active_affiliate_is_blocked(): void
    {
        [$product, $item] = $this->promotedDraftPair(withAffiliate: false);

        $result = app(EvaluateProductAutomationReadinessAction::class)->execute($item->fresh());

        $this->assertSame(ProductAutomationReadiness::Blocked, $result->readiness);
        $this->assertContains('no_active_affiliate_link', $result->exceptionCodes);
    }

    public function test_manual_affiliate_fix_clears_blocked_state(): void
    {
        $merchant = $this->createActiveMerchant('partner-a');
        [$product, $item] = $this->promotedDraftPair(merchant: $merchant, withAffiliate: false);

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://partner-a.example/dp/B0ABCDEFGH?aff=test',
            'external_product_id' => 'B0ABCDEFGH',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);

        $item->affiliate_link_id = AffiliateLink::query()->where('product_id', $product->id)->value('id');
        $item->save();

        $result = app(EvaluateProductAutomationReadinessAction::class)->execute($item->fresh());

        $this->assertNotContains('no_active_affiliate_link', $result->exceptionCodes);
    }

    public function test_missing_primary_category_is_needs_review(): void
    {
        [$product, $item] = $this->promotedDraftPair(withPrimaryCategory: false);

        $result = app(EvaluateProductAutomationReadinessAction::class)->execute($item->fresh());

        $this->assertSame(ProductAutomationReadiness::NeedsReview, $result->readiness);
        $this->assertContains('missing_primary_category', $result->exceptionCodes);
    }

    public function test_missing_price_is_needs_review(): void
    {
        [$product, $item] = $this->promotedDraftPair(priceAmount: null);

        $result = app(EvaluateProductAutomationReadinessAction::class)->execute($item->fresh());

        $this->assertSame(ProductAutomationReadiness::NeedsReview, $result->readiness);
        $this->assertContains('missing_or_ambiguous_price', $result->exceptionCodes);
    }

    public function test_published_product_readiness_is_null(): void
    {
        [$product, $item] = $this->promotedDraftPair();
        $product->update(['status' => ProductStatus::Published, 'published_at' => now()]);

        $result = app(EvaluateProductAutomationReadinessAction::class)->execute($item->fresh());

        $this->assertNull($result->readiness);
    }

    public function test_pre_promotion_clean_item_is_needs_review_with_not_promoted(): void
    {
        $merchant = $this->createActiveMerchant('partner-a');
        $item = $this->sourcingItemWithoutProduct($merchant);

        $result = app(EvaluateProductAutomationReadinessAction::class)->execute($item);

        $this->assertSame(ProductAutomationReadiness::NeedsReview, $result->readiness);
        $this->assertContains('not_promoted', $result->exceptionCodes);
    }

    public function test_pre_promotion_item_without_product_is_never_ready(): void
    {
        $merchant = $this->createActiveMerchant('partner-a');
        $item = $this->sourcingItemWithoutProduct($merchant);

        $result = app(EvaluateProductAutomationReadinessAction::class)->execute($item);

        $this->assertNotSame(ProductAutomationReadiness::Ready, $result->readiness);
    }

    public function test_persist_action_stores_readiness_and_codes(): void
    {
        [$product, $item] = $this->promotedDraftPair(withImage: false);

        $item = app(EvaluateAndPersistProductAutomationReadinessAction::class)->execute($item->fresh());

        $this->assertSame(ProductAutomationReadiness::Blocked, $item->readiness);
        $this->assertContains('no_image', $item->exception_codes ?? []);
        $this->assertArrayHasKey('readiness_evaluated_at', $item->enrichment['metadata'] ?? []);
    }

    /**
     * @return array{0: Product, 1: CatalogCandidateSourcingItem}
     */
    private function promotedDraftPair(
        ?Merchant $merchant = null,
        bool $withImage = true,
        bool $withAffiliate = true,
        bool $withPrimaryCategory = true,
        ?string $priceAmount = '999.00',
        array $enrichmentOverrides = [],
    ): array {
        $merchant ??= $this->createActiveMerchant('partner-a');
        $category = Category::query()->create([
            'name' => 'Home',
            'slug' => 'home',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'name' => 'French Press Gift',
            'slug' => 'french-press-gift',
            'brand' => 'BrandX',
            'short_description' => 'Compact press.',
            'price_amount' => $priceAmount,
            'price_currency' => 'INR',
            'status' => ProductStatus::Draft,
        ]);

        if ($withPrimaryCategory) {
            $product->categories()->attach($category->id, ['is_primary' => true]);
        }

        if ($withImage) {
            ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => 'images/gift.jpg',
                'is_primary' => true,
            ]);
        }

        $linkId = null;

        if ($withAffiliate) {
            $link = AffiliateLink::query()->create([
                'product_id' => $product->id,
                'merchant_id' => $merchant->id,
                'url' => 'https://partner-a.example/dp/B0ABCDEFGH?aff=test',
                'external_product_id' => 'B0ABCDEFGH',
                'status' => AffiliateLinkStatus::Active,
                'is_primary' => true,
            ]);
            $linkId = $link->id;
        }

        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French Press Gift',
        ]);

        $enrichment = array_replace_recursive([
            'catalog_candidate_id' => $candidate->id,
            'merchant_id' => $merchant->id,
            'external_product_id' => 'B0ABCDEFGH',
            'affiliate_url' => 'https://partner-a.example/dp/B0ABCDEFGH?aff=test',
            'affiliate_ready' => true,
            'name' => 'French Press Gift',
            'short_description' => 'Compact press.',
            'description' => 'A stainless steel french press.',
            'brand' => 'BrandX',
            'price_amount' => '999.00',
            'price_currency' => 'INR',
            'primary_category_id' => $category->id,
            'taxonomy' => [
                'category_ids' => [$category->id],
                'occasion_ids' => [],
                'relationship_ids' => [],
                'recipient_type_ids' => [],
                'interest_ids' => [],
                'profession_ids' => [],
                'gift_type_ids' => [],
            ],
            'image_urls' => ['https://example.test/images/gift.jpg'],
            'exception_codes' => [],
            'metadata' => [],
        ], $enrichmentOverrides);

        $item = CatalogCandidateSourcingItem::query()->create([
            'catalog_candidate_sourcing_run_id' => CatalogCandidateSourcingRun::factory()->create()->id,
            'catalog_candidate_id' => $candidate->id,
            'merchant_id' => $merchant->id,
            'selected_offer' => [
                'merchant_id' => $merchant->id,
                'merchant_slug' => $merchant->slug,
                'source_url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                'normalized_url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                'title' => 'French Press Gift',
                'snippet' => 'French press',
                'external_product_id' => 'B0ABCDEFGH',
                'external_id_source' => 'extractor',
                'image_urls' => $enrichment['image_urls'],
            ],
            'enrichment' => $enrichment,
            'product_id' => $product->id,
            'affiliate_link_id' => $linkId,
            'status' => CatalogCandidateSourcingItemStatus::Succeeded,
        ]);

        return [$product, $item];
    }

    private function sourcingItemWithoutProduct(Merchant $merchant): CatalogCandidateSourcingItem
    {
        $category = Category::query()->create([
            'name' => 'Kitchen',
            'slug' => 'kitchen',
            'is_active' => true,
        ]);

        $candidate = CatalogCandidate::factory()->create();

        return CatalogCandidateSourcingItem::query()->create([
            'catalog_candidate_sourcing_run_id' => CatalogCandidateSourcingRun::factory()->create()->id,
            'catalog_candidate_id' => $candidate->id,
            'merchant_id' => $merchant->id,
            'selected_offer' => [
                'merchant_id' => $merchant->id,
                'merchant_slug' => $merchant->slug,
                'source_url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                'normalized_url' => 'https://partner-a.example/dp/B0ABCDEFGH',
                'title' => 'French Press',
                'snippet' => 'French press',
                'external_product_id' => 'B0ABCDEFGH',
                'external_id_source' => 'extractor',
                'image_urls' => [],
            ],
            'enrichment' => [
                'catalog_candidate_id' => $candidate->id,
                'merchant_id' => $merchant->id,
                'external_product_id' => 'B0ABCDEFGH',
                'affiliate_url' => 'https://partner-a.example/dp/B0ABCDEFGH?aff=test',
                'affiliate_ready' => true,
                'name' => 'French Press',
                'short_description' => 'Compact.',
                'description' => 'Press.',
                'brand' => 'BrandX',
                'price_amount' => '999.00',
                'price_currency' => 'INR',
                'primary_category_id' => $category->id,
                'taxonomy' => [
                    'category_ids' => [$category->id],
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
    }
}
