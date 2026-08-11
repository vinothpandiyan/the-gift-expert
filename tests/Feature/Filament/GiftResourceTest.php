<?php

namespace Tests\Feature\Filament;

use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Filament\Resources\Gifts\Pages\CreateGift;
use App\Filament\Resources\Gifts\Pages\EditGift;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GiftResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_create_a_gift_as_draft(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateGift::class)
            ->fillForm([
                'name' => 'Ceramic Mug',
                'slug' => 'ceramic-mug',
                'price_currency' => 'INR',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', [
            'name' => 'Ceramic Mug',
            'slug' => 'ceramic-mug',
            'status' => ProductStatus::Draft->value,
        ]);
    }

    public function test_it_can_edit_a_gift(): void
    {
        $this->actingAs(User::factory()->create());

        $product = Product::factory()->create([
            'name' => 'Old Name',
            'slug' => 'old-name',
        ]);

        Livewire::test(EditGift::class, [
            'record' => $product->getRouteKey(),
        ])
            ->fillForm([
                'name' => 'New Name',
                'slug' => 'new-name',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'New Name',
            'slug' => 'new-name',
        ]);
    }

    public function test_publish_action_blocks_when_requirements_are_missing(): void
    {
        $this->actingAs(User::factory()->create());

        $product = Product::factory()->create([
            'status' => ProductStatus::Draft,
        ]);

        Livewire::test(EditGift::class, [
            'record' => $product->getRouteKey(),
        ])
            ->callAction('publish');

        $this->assertSame(ProductStatus::Draft, $product->fresh()->status);
    }

    public function test_publish_action_publishes_when_requirements_are_met(): void
    {
        $this->actingAs(User::factory()->create());

        $product = $this->publishableProduct();

        Livewire::test(EditGift::class, [
            'record' => $product->getRouteKey(),
        ])
            ->callAction('publish')
            ->assertHasNoActionErrors();

        $product->refresh();

        $this->assertSame(ProductStatus::Published, $product->status);
        $this->assertNotNull($product->published_at);
    }

    public function test_archive_action_archives_a_gift(): void
    {
        $this->actingAs(User::factory()->create());

        $product = Product::factory()->published()->create();

        Livewire::test(EditGift::class, [
            'record' => $product->getRouteKey(),
        ])
            ->callAction('archive')
            ->assertHasNoActionErrors();

        $this->assertSame(ProductStatus::Archived, $product->fresh()->status);
    }

    private function publishableProduct(): Product
    {
        $merchant = Merchant::query()->create([
            'name' => 'Example Merchant',
            'slug' => 'example-merchant',
            'affiliate_network' => 'example',
        ]);

        $product = Product::factory()->create([
            'status' => ProductStatus::Draft,
            'price_amount' => '999.00',
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
