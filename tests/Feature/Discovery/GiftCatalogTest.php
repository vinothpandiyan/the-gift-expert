<?php

namespace Tests\Feature\Discovery;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Support\DiscoveryUrl;
use App\Support\Terminology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GiftCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_gift_detail_resolves(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Ceramic Mug',
            'slug' => 'ceramic-mug',
            'short_description' => 'A lovely mug',
            'price_amount' => '499.00',
        ]);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee('Ceramic Mug', false)
            ->assertSee('A lovely mug', false)
            ->assertSee(Terminology::gift(), false)
            ->assertSee('View at Example Merchant', false);
    }

    public function test_draft_gift_returns_not_found(): void
    {
        Product::factory()->draft()->create(['slug' => 'draft-gift']);

        $this->get(DiscoveryUrl::gift('draft-gift'))->assertNotFound();
    }

    public function test_archived_gift_returns_not_found(): void
    {
        Product::factory()->create([
            'slug' => 'archived-gift',
            'status' => ProductStatus::Archived,
        ]);

        $this->get(DiscoveryUrl::gift('archived-gift'))->assertNotFound();
    }

    public function test_soft_deleted_gift_returns_not_found(): void
    {
        $product = Product::factory()->published()->create(['slug' => 'deleted-gift']);
        $product->delete();

        $this->get(DiscoveryUrl::gift('deleted-gift'))->assertNotFound();
    }
}
