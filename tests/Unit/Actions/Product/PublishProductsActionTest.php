<?php

namespace Tests\Unit\Actions\Product;

use App\Actions\Product\PublishProductAction;
use App\Actions\Product\PublishProductsAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\LaunchPublicationResult;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\AffiliateLink;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PublishProductsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_publication_calls_the_canonical_action_once_per_product(): void
    {
        $first = $this->publishable('one');
        $second = $this->publishable('two');

        $publish = Mockery::mock(PublishProductAction::class);
        $publish->shouldReceive('execute')->twice()->andReturn(['warnings' => []]);
        $this->app->instance(PublishProductAction::class, $publish);

        $attempts = app(PublishProductsAction::class)->execute([$first, $second], 'test:bulk');

        $this->assertSame(2, $attempts->count());
        $this->assertTrue($attempts->every(
            fn ($attempt): bool => $attempt->result === LaunchPublicationResult::Published,
        ));
    }

    public function test_it_skips_non_drafts_and_records_the_reason(): void
    {
        $draft = $this->publishable('draft');
        $published = $this->publishable('published');
        $published->update([
            'status' => ProductStatus::Published,
            'published_at' => now(),
        ]);

        $attempts = app(PublishProductsAction::class)->execute([$published, $draft], 'test:bulk');

        $this->assertSame(LaunchPublicationResult::Skipped, $attempts[0]->result);
        $this->assertSame('already_published', $attempts[0]->reason);
        $this->assertSame(LaunchPublicationResult::Published, $attempts[1]->result);
        $this->assertSame(ProductStatus::Published, $draft->fresh()->status);
        $this->assertSame(ProductStatus::Published, $published->fresh()->status);
    }

    public function test_it_records_a_failure_reason_and_continues(): void
    {
        $blocked = $this->publishable('blocked', withImage: false);
        $ready = $this->publishable('ready');

        $attempts = app(PublishProductsAction::class)->execute([$blocked, $ready], 'test:bulk');

        $this->assertSame(LaunchPublicationResult::Failed, $attempts[0]->result);
        $this->assertStringContainsString('image', $attempts[0]->reason);
        $this->assertSame(ProductStatus::Draft, $blocked->fresh()->status);
        $this->assertSame(LaunchPublicationResult::Published, $attempts[1]->result);
        $this->assertSame(ProductStatus::Published, $ready->fresh()->status);
    }

    private function publishable(string $suffix, bool $withImage = true): Product
    {
        $merchant = Merchant::query()->create([
            'name' => 'Merchant '.$suffix,
            'slug' => 'merchant-'.$suffix,
            'affiliate_network' => 'example',
        ]);
        $category = Category::query()->create([
            'name' => 'Home',
            'slug' => 'home-'.$suffix,
            'is_active' => true,
        ]);
        $product = Product::factory()->create([
            'name' => 'Gift '.$suffix,
            'slug' => 'gift-'.$suffix,
            'status' => ProductStatus::Draft,
            'price_amount' => '500.00',
            'taxonomy_classification_status' => TaxonomyClassificationStatus::AiAccepted,
        ]);
        $product->categories()->sync([$category->id => ['is_primary' => true]]);

        if ($withImage) {
            ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => 'images/'.$suffix.'.jpg',
                'is_primary' => true,
            ]);
        }

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://example.com/'.$suffix,
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);

        return $product;
    }
}
