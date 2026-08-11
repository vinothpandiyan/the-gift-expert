<?php

namespace Tests\Feature\Discovery;

use App\Models\Category;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_nested_category_paths(): void
    {
        $root = Category::query()->create([
            'name' => 'Gifts for Him',
            'slug' => 'gifts-for-him',
            'is_active' => true,
        ]);

        $child = Category::query()->create([
            'parent_id' => $root->id,
            'name' => 'Husband',
            'slug' => 'gifts-for-husband',
            'is_active' => true,
        ]);

        $this->assertSame('gifts-for-him/gifts-for-husband', $child->fresh()->full_path);

        $this->get(DiscoveryUrl::giftIdeasCategory($child->fresh()->full_path))
            ->assertOk()
            ->assertSee('Husband', false);
    }

    public function test_inactive_category_returns_not_found(): void
    {
        Category::query()->create([
            'name' => 'Hidden',
            'slug' => 'hidden',
            'is_active' => false,
        ]);

        $this->get(DiscoveryUrl::giftIdeasCategory('hidden'))
            ->assertNotFound();
    }

    public function test_soft_deleted_category_returns_not_found(): void
    {
        $category = Category::query()->create([
            'name' => 'Deleted',
            'slug' => 'deleted',
            'is_active' => true,
        ]);

        $category->delete();

        $this->get(DiscoveryUrl::giftIdeasCategory('deleted'))
            ->assertNotFound();
    }
}
