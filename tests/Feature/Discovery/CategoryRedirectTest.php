<?php

namespace Tests\Feature\Discovery;

use App\Models\Category;
use App\Models\CategoryPathRedirect;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_category_path_redirects_to_new_path(): void
    {
        $category = Category::query()->create([
            'name' => 'Gifts for Him',
            'slug' => 'gifts-for-him',
            'is_active' => true,
        ]);

        $category->update(['slug' => 'gifts-for-men']);

        $response = $this->get(DiscoveryUrl::giftIdeasCategory('gifts-for-him'));

        $response->assertStatus(301);
        $response->assertRedirect(DiscoveryUrl::giftIdeasCategory('gifts-for-men'));
    }

    public function test_collapsed_category_redirect_chain_resolves_in_one_hop(): void
    {
        Category::query()->create([
            'name' => 'Root C',
            'slug' => 'root-c',
            'is_active' => true,
        ]);

        CategoryPathRedirect::query()->create([
            'from_path' => 'root-a',
            'to_path' => 'root-c',
        ]);

        CategoryPathRedirect::query()->create([
            'from_path' => 'root-b',
            'to_path' => 'root-c',
        ]);

        $response = $this->get(DiscoveryUrl::giftIdeasCategory('root-a'));

        $response->assertStatus(301);
        $response->assertRedirect(DiscoveryUrl::giftIdeasCategory('root-c'));
    }

    public function test_category_redirect_to_missing_target_returns_not_found(): void
    {
        CategoryPathRedirect::query()->create([
            'from_path' => 'old-path',
            'to_path' => 'missing-path',
        ]);

        $this->get(DiscoveryUrl::giftIdeasCategory('old-path'))
            ->assertNotFound();
    }
}
