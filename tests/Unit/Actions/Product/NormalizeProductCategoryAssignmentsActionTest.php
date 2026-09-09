<?php

namespace Tests\Unit\Actions\Product;

use App\Actions\Product\NormalizeProductCategoryAssignmentsAction;
use App\Models\Category;
use App\Models\SeoLandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class NormalizeProductCategoryAssignmentsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_electronics_is_primary_only(): void
    {
        $electronics = $this->category('Electronics', 'electronics');

        $result = app(NormalizeProductCategoryAssignmentsAction::class)->execute($electronics->id);

        $this->assertSame($electronics->id, $result->primaryCategoryId);
        $this->assertSame([$electronics->id], $result->categoryIds);
    }

    public function test_jewellery_attaches_fashion_as_secondary_ancestor(): void
    {
        $fashion = $this->category('Fashion & Accessories', 'fashion-and-accessories');
        $jewellery = $this->category('Jewellery', 'jewellery', $fashion->id);

        $result = app(NormalizeProductCategoryAssignmentsAction::class)->execute($jewellery->id);

        $this->assertSame($jewellery->id, $result->primaryCategoryId);
        $this->assertSame([$jewellery->id, $fashion->id], $result->categoryIds);
    }

    public function test_kitchen_attaches_home_as_secondary_ancestor(): void
    {
        $home = $this->category('Home & Living', 'home-and-living');
        $kitchen = $this->category('Kitchen & Dining', 'kitchen-and-dining', $home->id);

        $result = app(NormalizeProductCategoryAssignmentsAction::class)->execute($kitchen->id);

        $this->assertSame($kitchen->id, $result->primaryCategoryId);
        $this->assertSame([$kitchen->id, $home->id], $result->categoryIds);
    }

    public function test_inactive_ancestor_is_skipped(): void
    {
        $root = $this->category('Root', 'root-merch');
        $inactive = $this->category('Inactive Parent', 'inactive-parent', $root->id, active: false);
        $leaf = $this->category('Leaf', 'leaf-merch', $inactive->id);

        $result = app(NormalizeProductCategoryAssignmentsAction::class)->execute($leaf->id);

        $this->assertSame([$leaf->id, $root->id], $result->categoryIds);
        $this->assertNotContains($inactive->id, $result->categoryIds);
    }

    public function test_composite_category_cannot_become_primary(): void
    {
        $this->category('Birthday Gifts', 'birthday-gifts', active: false);
        $composite = $this->category('Gifts for Him', 'gifts-for-him', active: false);

        $this->expectException(InvalidArgumentException::class);

        app(NormalizeProductCategoryAssignmentsAction::class)->execute($composite->id);
    }

    public function test_mapped_seo_category_cannot_become_primary(): void
    {
        $page = SeoLandingPage::factory()->create();
        $mapped = $this->category('Birthday Gifts for Husband', 'birthday-gifts-for-husband');
        $mapped->canonical_seo_landing_page_id = $page->id;
        $mapped->save();

        $this->expectException(InvalidArgumentException::class);

        app(NormalizeProductCategoryAssignmentsAction::class)->execute($mapped->id);
    }

    public function test_personalized_gifts_cannot_become_primary(): void
    {
        $personalized = $this->category('Personalized Gifts', 'personalized-gifts', active: false);

        $this->expectException(InvalidArgumentException::class);

        app(NormalizeProductCategoryAssignmentsAction::class)->execute($personalized->id);
    }

    private function category(string $name, string $slug, ?int $parentId = null, bool $active = true): Category
    {
        return Category::query()->create([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'is_active' => $active,
            'sort_order' => 1,
        ]);
    }
}
