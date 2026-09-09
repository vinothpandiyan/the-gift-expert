<?php

namespace Tests\Unit\Actions\CuratedCatalog;

use App\Actions\CuratedCatalog\BuildCuratedRelationshipHintFingerprintAction;
use App\Actions\CuratedCatalog\BuildCuratedTaxonomyContentFingerprintAction;
use App\Actions\CuratedCatalog\ShouldReclassifyCuratedMerchantProductAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\CatalogSourceListKind;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogProductSource;
use App\Models\CatalogSourceList;
use App\Models\Category;
use App\Models\Product;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\TestCase;

class ShouldReclassifyCuratedMerchantProductActionTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use RefreshDatabase;

    public function test_none_should_classify(): void
    {
        $product = $this->product(TaxonomyClassificationStatus::None);

        $decision = app(ShouldReclassifyCuratedMerchantProductAction::class)->execute($product);

        $this->assertTrue($decision->shouldReclassify);
        $this->assertSame('none', $decision->reason);
    }

    public function test_human_approved_does_not_classify(): void
    {
        $product = $this->product(TaxonomyClassificationStatus::HumanApproved);
        $this->storeFingerprints($product);

        $decision = app(ShouldReclassifyCuratedMerchantProductAction::class)->execute($product);

        $this->assertFalse($decision->shouldReclassify);
        $this->assertSame('human_locked', $decision->reason);
    }

    public function test_human_overridden_does_not_classify(): void
    {
        $product = $this->product(TaxonomyClassificationStatus::HumanOverridden);
        $this->storeFingerprints($product);

        $decision = app(ShouldReclassifyCuratedMerchantProductAction::class)->execute($product);

        $this->assertFalse($decision->shouldReclassify);
        $this->assertSame('human_locked', $decision->reason);
    }

    public function test_force_classifies_human_locked(): void
    {
        $product = $this->product(TaxonomyClassificationStatus::HumanApproved);

        $decision = app(ShouldReclassifyCuratedMerchantProductAction::class)->execute($product, force: true);

        $this->assertTrue($decision->shouldReclassify);
        $this->assertSame('forced', $decision->reason);
    }

    public function test_failed_does_not_retry_without_flag(): void
    {
        $product = $this->product(TaxonomyClassificationStatus::Failed);
        $product->taxonomy_classification_version = 1;
        $product->save();

        $decision = app(ShouldReclassifyCuratedMerchantProductAction::class)->execute($product);

        $this->assertFalse($decision->shouldReclassify);
        $this->assertSame('failed_not_retried', $decision->reason);
    }

    public function test_failed_retries_when_permitted(): void
    {
        $product = $this->product(TaxonomyClassificationStatus::Failed);

        $decision = app(ShouldReclassifyCuratedMerchantProductAction::class)->execute($product, retryFailed: true);

        $this->assertTrue($decision->shouldReclassify);
        $this->assertSame('retry_failed', $decision->reason);
    }

    public function test_version_bump_reclassifies_ai_managed(): void
    {
        $product = $this->product(TaxonomyClassificationStatus::AiAccepted);
        $this->attachPrimary($product);
        $product->taxonomy_classification_version = 1;
        $product->save();
        $this->storeFingerprints($product);

        config(['curated_catalog.taxonomy_classification.version' => 2]);

        $decision = app(ShouldReclassifyCuratedMerchantProductAction::class)->execute($product->fresh());

        $this->assertTrue($decision->shouldReclassify);
        $this->assertSame('classification_version', $decision->reason);
    }

    public function test_content_fingerprint_change_reclassifies(): void
    {
        $product = $this->product(TaxonomyClassificationStatus::AiAccepted, name: 'Original Title');
        $this->attachPrimary($product);
        $product->taxonomy_classification_version = 1;
        $product->save();
        $this->storeFingerprints($product);

        $product->name = 'Materially Different Title';
        $product->save();

        $decision = app(ShouldReclassifyCuratedMerchantProductAction::class)->execute($product->fresh());

        $this->assertTrue($decision->shouldReclassify);
        $this->assertSame('content_fingerprint', $decision->reason);
    }

    public function test_relationship_hint_change_reclassifies(): void
    {
        $product = $this->product(TaxonomyClassificationStatus::AiAccepted);
        $this->attachPrimary($product);
        $product->taxonomy_classification_version = 1;
        $product->save();
        $this->storeFingerprints($product);

        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);
        $this->attachHint($product, $husband, 'Gifts for Husband');

        $decision = app(ShouldReclassifyCuratedMerchantProductAction::class)->execute($product->fresh());

        $this->assertTrue($decision->shouldReclassify);
        $this->assertSame('relationship_hints', $decision->reason);
    }

    public function test_quarterly_list_does_not_change_hint_fingerprint(): void
    {
        $product = $this->product(TaxonomyClassificationStatus::AiAccepted);
        $this->attachPrimary($product);
        $product->taxonomy_classification_version = 1;
        $product->save();
        $this->storeFingerprints($product);
        $before = $product->taxonomy_relationship_hint_fingerprint;

        $list = CatalogSourceList::query()->create([
            'merchant_id' => $product->affiliateLinks()->first()->merchant_id,
            'name' => '01 - Gift Ideas - Q1 2026',
            'normalized_name' => '01-gift-ideas-q1-2026',
            'kind' => CatalogSourceListKind::QuarterlyArchive,
            'is_active' => true,
        ]);
        CatalogProductSource::query()->create([
            'affiliate_link_id' => $product->affiliateLinks()->first()->id,
            'catalog_source_list_id' => $list->id,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'occurrence_count' => 1,
        ]);

        $after = app(BuildCuratedRelationshipHintFingerprintAction::class)->execute($product->fresh());

        $this->assertSame($before, $after);
        $decision = app(ShouldReclassifyCuratedMerchantProductAction::class)->execute($product->fresh());
        $this->assertFalse($decision->shouldReclassify);
    }

    public function test_ai_accepted_without_material_change_is_current(): void
    {
        $product = $this->product(TaxonomyClassificationStatus::AiAccepted);
        $this->attachPrimary($product);
        $product->taxonomy_classification_version = 1;
        $product->save();
        $this->storeFingerprints($product);

        $decision = app(ShouldReclassifyCuratedMerchantProductAction::class)->execute($product->fresh());

        $this->assertFalse($decision->shouldReclassify);
        $this->assertSame('current', $decision->reason);
    }

    private function product(TaxonomyClassificationStatus $status, string $name = 'French Press'): Product
    {
        $merchant = $this->configureCuratedAmazonMerchant();
        $product = Product::factory()->create([
            'name' => $name,
            'status' => ProductStatus::Draft,
            'taxonomy_classification_status' => $status,
            'taxonomy_classification_version' => 1,
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH?tag=test-tag-20',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

        return $product->fresh(['affiliateLinks.merchant']);
    }

    private function attachPrimary(Product $product): void
    {
        $category = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living-'.$product->id,
            'is_active' => true,
        ]);
        $product->categories()->attach($category->id, ['is_primary' => true]);
    }

    private function storeFingerprints(Product $product): void
    {
        $product->taxonomy_content_fingerprint = app(BuildCuratedTaxonomyContentFingerprintAction::class)->execute($product);
        $product->taxonomy_relationship_hint_fingerprint = app(BuildCuratedRelationshipHintFingerprintAction::class)->execute($product);
        $product->save();
    }

    private function attachHint(Product $product, Relationship $relationship, string $listName): void
    {
        $link = $product->affiliateLinks()->first();
        $list = CatalogSourceList::query()->create([
            'merchant_id' => $link->merchant_id,
            'name' => $listName,
            'normalized_name' => str($listName)->slug()->toString(),
            'kind' => CatalogSourceListKind::RecipientHint,
            'relationship_id' => $relationship->id,
            'is_active' => true,
        ]);
        CatalogProductSource::query()->create([
            'affiliate_link_id' => $link->id,
            'catalog_source_list_id' => $list->id,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'occurrence_count' => 1,
        ]);
    }
}
