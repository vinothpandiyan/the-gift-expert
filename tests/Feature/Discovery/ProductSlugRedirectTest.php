<?php

namespace Tests\Feature\Discovery;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductSlugRedirect;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSlugRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_gift_resolves_by_slug(): void
    {
        $product = Product::factory()->published()->create([
            'name' => 'Wooden Frame',
            'slug' => 'wooden-frame',
        ]);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee('Wooden Frame', false);
    }

    public function test_old_product_slug_redirects_to_published_slug(): void
    {
        $product = Product::factory()->published()->create([
            'slug' => 'personalized-wooden-frame',
        ]);

        ProductSlugRedirect::query()->create([
            'from_slug' => 'wooden-frame',
            'to_slug' => 'personalized-wooden-frame',
            'product_id' => $product->id,
        ]);

        $response = $this->get(DiscoveryUrl::gift('wooden-frame'));

        $response->assertStatus(301);
        $response->assertRedirect(DiscoveryUrl::gift('personalized-wooden-frame'));
    }

    public function test_it_does_not_redirect_to_draft_products(): void
    {
        Product::factory()->draft()->create([
            'slug' => 'draft-gift',
        ]);

        ProductSlugRedirect::query()->create([
            'from_slug' => 'old-draft-slug',
            'to_slug' => 'draft-gift',
        ]);

        $this->get(DiscoveryUrl::gift('old-draft-slug'))
            ->assertNotFound();
    }

    public function test_it_does_not_redirect_to_archived_products(): void
    {
        Product::factory()->create([
            'slug' => 'archived-gift',
            'status' => ProductStatus::Archived,
            'published_at' => now(),
        ]);

        ProductSlugRedirect::query()->create([
            'from_slug' => 'old-archived-slug',
            'to_slug' => 'archived-gift',
        ]);

        $this->get(DiscoveryUrl::gift('old-archived-slug'))
            ->assertNotFound();
    }

    public function test_current_draft_slug_returns_not_found(): void
    {
        Product::factory()->draft()->create([
            'slug' => 'still-draft',
        ]);

        $this->get(DiscoveryUrl::gift('still-draft'))
            ->assertNotFound();
    }

    public function test_current_archived_slug_returns_not_found(): void
    {
        Product::factory()->create([
            'slug' => 'now-archived',
            'status' => ProductStatus::Archived,
        ]);

        $this->get(DiscoveryUrl::gift('now-archived'))
            ->assertNotFound();
    }
}
