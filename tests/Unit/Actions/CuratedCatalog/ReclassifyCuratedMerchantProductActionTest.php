<?php

namespace Tests\Unit\Actions\CuratedCatalog;

use App\Actions\CuratedCatalog\ClassifyCuratedMerchantProductAction;
use App\Actions\CuratedCatalog\EnrichCuratedMerchantProductAction;
use App\Actions\CuratedCatalog\ReclassifyCuratedMerchantProductAction;
use App\Actions\CuratedCatalog\RejectCuratedTaxonomyProposalAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyClassificationWarningCode;
use App\Models\AffiliateLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsClassificationReviewFixtures;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\Support\FakesCommercialEnrichment;
use Tests\TestCase;

class ReclassifyCuratedMerchantProductActionTest extends TestCase
{
    use BuildsClassificationReviewFixtures;
    use ConfiguresCuratedCatalog;
    use FakesCommercialEnrichment;
    use RefreshDatabase;

    public function test_human_overridden_reclassify_keeps_applied_taxonomy_and_stores_pending_proposal(): void
    {
        Http::preventStrayRequests();
        $merchant = $this->configureCuratedAmazonMerchant();
        $user = User::factory()->create();
        $electronics = $this->merchandisingCategory('Electronics', 'electronics');
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');

        $product = $this->reviewProduct($electronics, [], TaxonomyClassificationStatus::HumanOverridden);
        $product->taxonomy_approved_at = now()->subHour();
        $product->taxonomy_approved_by_user_id = $user->id;
        $product->save();
        $product->categories()->attach($electronics->id, ['is_primary' => true]);

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH?tag=test-tag-20',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                    ],
                    'confidence' => [
                        'primary_category' => 0.70,
                    ],
                ]),
            ),
        ]);

        $result = app(ReclassifyCuratedMerchantProductAction::class)->execute($product->fresh());

        $product->refresh();
        $this->assertTrue($result->classified);
        $this->assertSame(TaxonomyClassificationStatus::HumanOverridden, $product->taxonomy_classification_status);
        $this->assertTrue($product->taxonomy_proposal_pending);
        $this->assertSame([$electronics->id], $product->categories()->pluck('categories.id')->all());
        $this->assertSame($home->id, $product->taxonomy_classification_proposal['primary_category_id'] ?? null);
        $this->assertSame(ProductStatus::Draft, $product->status);
        Http::assertSentCount(1);
    }

    public function test_human_reclassify_failure_keeps_applied_taxonomy(): void
    {
        Http::preventStrayRequests();
        $merchant = $this->configureCuratedAmazonMerchant();
        $electronics = $this->merchandisingCategory('Electronics', 'electronics');
        $product = $this->reviewProduct($electronics, [], TaxonomyClassificationStatus::HumanApproved);
        $product->categories()->attach($electronics->id, ['is_primary' => true]);

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH?tag=test-tag-20',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

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

        app(ReclassifyCuratedMerchantProductAction::class)->execute($product->fresh());

        $product->refresh();
        $this->assertSame(TaxonomyClassificationStatus::HumanApproved, $product->taxonomy_classification_status);
        $this->assertTrue($product->taxonomy_proposal_pending);
        $this->assertSame([$electronics->id], $product->categories()->pluck('categories.id')->all());
        $this->assertContains(
            TaxonomyClassificationWarningCode::MissingPrimaryCategory->value,
            $product->taxonomy_review_reasons ?? [],
        );
    }

    public function test_non_human_reclassify_can_auto_accept(): void
    {
        Http::preventStrayRequests();
        $merchant = $this->configureCuratedAmazonMerchant();
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $product = $this->reviewProduct($home, [], TaxonomyClassificationStatus::Failed);

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH?tag=test-tag-20',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

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

        $result = app(ReclassifyCuratedMerchantProductAction::class)->execute($product->fresh());

        $product->refresh();
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertFalse($product->taxonomy_proposal_pending);
        $this->assertTrue($product->categories()->where('categories.id', $home->id)->exists());
        $this->assertTrue($result->classified);
    }

    public function test_reject_marks_review_proposal_failed_without_deleting_product(): void
    {
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $product = $this->reviewProduct($home);

        $rejected = app(RejectCuratedTaxonomyProposalAction::class)->execute($product);

        $this->assertSame(TaxonomyClassificationStatus::Failed, $rejected->taxonomy_classification_status);
        $this->assertContains(
            TaxonomyClassificationWarningCode::HumanRejectedProposal->value,
            $rejected->taxonomy_review_reasons,
        );
        $this->assertSame(ProductStatus::Draft, $rejected->status);
        $this->assertNotNull($rejected->fresh());
    }

    public function test_routine_classify_still_skips_human_locked_products(): void
    {
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $product = $this->reviewProduct($home, [], TaxonomyClassificationStatus::HumanApproved);
        $product->categories()->attach($home->id, ['is_primary' => true]);

        $this->mock(EnrichCuratedMerchantProductAction::class, function ($mock): void {
            $mock->shouldNotReceive('execute');
        });

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product->fresh());

        $this->assertFalse($result->classified);
        $this->assertSame('human_locked', $result->reason);
    }
}
