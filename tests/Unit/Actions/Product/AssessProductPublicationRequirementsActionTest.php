<?php

namespace Tests\Unit\Actions\Product;

use App\Actions\Product\AssessProductPublicationRequirementsAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\AffiliateLink;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessProductPublicationRequirementsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_blocking_codes_for_missing_requirements(): void
    {
        $product = Product::factory()->create([
            'name' => '',
            'slug' => '',
            'price_amount' => null,
        ]);

        $result = app(AssessProductPublicationRequirementsAction::class)->execute($product);

        $this->assertContains('missing_name', $result['error_codes']);
        $this->assertContains('missing_slug', $result['error_codes']);
        $this->assertContains('no_image', $result['error_codes']);
        $this->assertContains('no_active_affiliate_link', $result['error_codes']);
        $this->assertContains('missing_primary_category', $result['error_codes']);
        $this->assertContains('classification_not_publishable', $result['error_codes']);
        $this->assertContains('missing_or_ambiguous_price', $result['warnings']);
    }

    public function test_it_returns_no_errors_for_publishable_classified_product(): void
    {
        $product = $this->classifiedPublishableProduct(TaxonomyClassificationStatus::AiAccepted);

        $result = app(AssessProductPublicationRequirementsAction::class)->execute($product->fresh());

        $this->assertSame([], $result['error_codes']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_unclassified_statuses_are_not_publishable(): void
    {
        foreach ([
            TaxonomyClassificationStatus::None,
            TaxonomyClassificationStatus::Review,
            TaxonomyClassificationStatus::Failed,
            TaxonomyClassificationStatus::AiProposed,
        ] as $status) {
            $product = $this->classifiedPublishableProduct($status);

            $result = app(AssessProductPublicationRequirementsAction::class)->execute($product->fresh());

            $this->assertContains('classification_not_publishable', $result['error_codes'], $status->value);
        }
    }

    public function test_human_statuses_are_publishable(): void
    {
        foreach ([
            TaxonomyClassificationStatus::HumanApproved,
            TaxonomyClassificationStatus::HumanOverridden,
        ] as $status) {
            $product = $this->classifiedPublishableProduct($status);

            $result = app(AssessProductPublicationRequirementsAction::class)->execute($product->fresh());

            $this->assertSame([], $result['error_codes'], $status->value);
        }
    }

    private function classifiedPublishableProduct(TaxonomyClassificationStatus $status): Product
    {
        $merchant = Merchant::query()->create([
            'name' => 'Merchant',
            'slug' => 'merchant-'.uniqid(),
            'affiliate_network' => 'fake',
        ]);

        $category = Category::query()->create([
            'name' => 'Home',
            'slug' => 'home-'.uniqid(),
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'price_amount' => '100.00',
            'status' => ProductStatus::Draft,
            'taxonomy_classification_status' => $status,
        ]);

        $product->categories()->attach($category->id, ['is_primary' => true]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'images/gift.jpg',
            'is_primary' => true,
        ]);

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://merchant.example/product',
            'external_product_id' => 'SKU123',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);

        return $product;
    }
}
