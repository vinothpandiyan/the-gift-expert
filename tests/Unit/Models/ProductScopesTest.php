<?php

namespace Tests\Unit\Models;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductScopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_scope_returns_only_published_products(): void
    {
        $published = Product::query()->create([
            'name' => 'Published Gift',
            'slug' => 'published-gift',
            'status' => ProductStatus::Published,
            'published_at' => now(),
        ]);

        Product::query()->create([
            'name' => 'Draft Gift',
            'slug' => 'draft-gift',
            'status' => ProductStatus::Draft,
        ]);

        $results = Product::query()->published()->pluck('id');

        $this->assertTrue($results->contains($published->id));
        $this->assertCount(1, $results);
    }

    public function test_category_full_path_is_not_mass_assignable(): void
    {
        $category = Category::query()->create([
            'name' => 'Gifts for Him',
            'slug' => 'gifts-for-him',
            'full_path' => 'ignored-value',
        ]);

        $this->assertSame('gifts-for-him', $category->full_path);
    }
}
