<?php

namespace Tests\Feature\Discovery;

use App\Models\Category;
use App\Models\Product;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_page_resolves_and_lists_only_published_gifts(): void
    {
        $category = Category::query()->create([
            'name' => 'Personalized',
            'slug' => 'personalized',
            'description' => 'Thoughtful picks',
            'is_active' => true,
        ]);

        $published = GiftCatalogTestHelpers::publishedGift(['name' => 'Published Frame', 'slug' => 'published-frame']);
        $draft = Product::factory()->draft()->create(['name' => 'Draft Frame', 'slug' => 'draft-frame']);

        $category->products()->attach($published->id, ['is_primary' => true]);
        $category->products()->attach($draft->id, ['is_primary' => false]);

        $this->get(DiscoveryUrl::giftIdeasCategory($category->full_path))
            ->assertOk()
            ->assertSee('Personalized', false)
            ->assertSee('Thoughtful picks', false)
            ->assertSee('Published Frame', false)
            ->assertDontSee('Draft Frame', false);
    }

    public function test_inactive_category_returns_not_found(): void
    {
        Category::query()->create([
            'name' => 'Hidden',
            'slug' => 'hidden-cat',
            'is_active' => false,
        ]);

        $this->get(DiscoveryUrl::giftIdeasCategory('hidden-cat'))->assertNotFound();
    }

    public function test_soft_deleted_category_returns_not_found(): void
    {
        $category = Category::query()->create([
            'name' => 'Gone',
            'slug' => 'gone-cat',
            'is_active' => true,
        ]);
        $category->delete();

        $this->get(DiscoveryUrl::giftIdeasCategory('gone-cat'))->assertNotFound();
    }

    public function test_category_pagination_works(): void
    {
        $category = Category::query()->create([
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true,
        ]);

        $slugs = [];

        foreach (range(1, 13) as $index) {
            $slug = "electronics-item-{$index}";
            $gift = GiftCatalogTestHelpers::publishedGift([
                'name' => "Unique Electronics {$index} Title",
                'slug' => $slug,
            ]);
            $category->products()->attach($gift->id);
            $slugs[] = $slug;
        }

        $pageOne = $this->get(DiscoveryUrl::giftIdeasCategory($category->full_path))->assertOk();
        $pageTwo = $this->get(DiscoveryUrl::giftIdeasCategory($category->full_path).'?page=2')->assertOk();

        $pageOneHtml = $pageOne->getContent();
        $pageTwoHtml = $pageTwo->getContent();

        $onPageOne = array_values(array_filter($slugs, fn (string $slug): bool => str_contains($pageOneHtml, '/gifts/'.$slug.'"') || str_contains($pageOneHtml, '/gifts/'.$slug.'?')));
        $onPageTwo = array_values(array_filter($slugs, fn (string $slug): bool => str_contains($pageTwoHtml, '/gifts/'.$slug.'"') || str_contains($pageTwoHtml, '/gifts/'.$slug.'?')));

        $this->assertCount(12, $onPageOne);
        $this->assertCount(13, $onPageTwo);
        $this->assertSame($onPageOne, array_slice($onPageTwo, 0, count($onPageOne)));
    }
}
