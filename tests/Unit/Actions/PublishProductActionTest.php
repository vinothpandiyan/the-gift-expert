<?php

namespace Tests\Unit\Actions;

use App\Actions\Product\PublishProductAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PublishProductActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_blocks_publish_without_image_or_active_affiliate_link(): void
    {
        $product = Product::query()->create([
            'name' => 'Gift',
            'slug' => 'gift',
            'status' => ProductStatus::Draft,
        ]);

        try {
            app(PublishProductAction::class)->execute($product);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $messages = $exception->errors()['status'];

            $this->assertContains('Add at least one gift image before publishing.', $messages);
            $this->assertContains('Add at least one active affiliate link before publishing.', $messages);
        }
    }

    public function test_it_warns_when_price_is_missing_but_still_publishes(): void
    {
        $product = $this->publishableProduct(priceAmount: null);

        $result = app(PublishProductAction::class)->execute($product->fresh());

        $this->assertSame(ProductStatus::Published, $product->fresh()->status);
        $this->assertContains('This gift has no price amount set.', $result['warnings']);
    }

    public function test_it_publishes_when_requirements_are_met(): void
    {
        $product = $this->publishableProduct(priceAmount: '999.00');

        app(PublishProductAction::class)->execute($product->fresh());

        $product->refresh();

        $this->assertSame(ProductStatus::Published, $product->status);
        $this->assertNotNull($product->published_at);
    }

    private function publishableProduct(?string $priceAmount): Product
    {
        $merchant = Merchant::query()->create([
            'name' => 'Example Merchant',
            'slug' => 'example-merchant',
            'affiliate_network' => 'example',
        ]);

        $product = Product::query()->create([
            'name' => 'Gift',
            'slug' => 'gift',
            'status' => ProductStatus::Draft,
            'price_amount' => $priceAmount,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'images/gift.jpg',
            'is_primary' => true,
        ]);

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://example.com/product',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);

        return $product;
    }
}
