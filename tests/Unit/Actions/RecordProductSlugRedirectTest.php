<?php

namespace Tests\Unit\Actions;

use App\Actions\Product\RecordProductSlugRedirectAction;
use App\Models\Product;
use App\Models\ProductSlugRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordProductSlugRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_slug_change_records_redirect(): void
    {
        $product = Product::query()->create([
            'name' => 'Wooden Frame',
            'slug' => 'wooden-frame',
            'status' => 'draft',
        ]);

        $product->update(['slug' => 'personalized-wooden-frame']);

        $this->assertDatabaseHas('product_slug_redirects', [
            'from_slug' => 'wooden-frame',
            'to_slug' => 'personalized-wooden-frame',
            'product_id' => $product->id,
        ]);
    }

    public function test_redirect_chain_collapses_to_final_slug(): void
    {
        ProductSlugRedirect::query()->create([
            'from_slug' => 'slug-a',
            'to_slug' => 'slug-b',
        ]);

        app(RecordProductSlugRedirectAction::class)->execute('slug-b', 'slug-c');

        $this->assertDatabaseHas('product_slug_redirects', [
            'from_slug' => 'slug-a',
            'to_slug' => 'slug-c',
        ]);

        $this->assertDatabaseHas('product_slug_redirects', [
            'from_slug' => 'slug-b',
            'to_slug' => 'slug-c',
        ]);
    }
}
