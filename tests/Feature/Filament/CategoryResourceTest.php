<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Models\Category;
use App\Models\SeoLandingPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_category_rebuilds_full_path(): void
    {
        $this->actingAs(User::factory()->create());

        $root = Category::query()->create([
            'name' => 'Gifts for Him',
            'slug' => 'gifts-for-him',
            'full_path' => 'gifts-for-him',
        ]);

        Livewire::test(CreateCategory::class)
            ->fillForm([
                'parent_id' => $root->id,
                'name' => 'Birthday',
                'slug' => 'birthday',
                'sort_order' => 0,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('categories', [
            'slug' => 'birthday',
            'parent_id' => $root->id,
            'full_path' => 'gifts-for-him/birthday',
        ]);
    }

    public function test_sibling_slug_uniqueness_is_enforced(): void
    {
        $this->actingAs(User::factory()->create());

        $root = Category::query()->create([
            'name' => 'Root',
            'slug' => 'root',
            'full_path' => 'root',
        ]);

        Category::query()->create([
            'parent_id' => $root->id,
            'name' => 'Child A',
            'slug' => 'child',
            'full_path' => 'root/child',
        ]);

        Livewire::test(CreateCategory::class)
            ->fillForm([
                'parent_id' => $root->id,
                'name' => 'Child B',
                'slug' => 'child',
                'sort_order' => 1,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_canonical_landing_page_mapping_can_be_saved_for_a_published_page(): void
    {
        $this->actingAs(User::factory()->create());

        $page = SeoLandingPage::factory()->published()->create([
            'heading' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'is_indexable' => true,
        ]);

        $category = Category::query()->create([
            'name' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'is_active' => true,
        ]);

        Livewire::test(EditCategory::class, [
            'record' => $category->getRouteKey(),
        ])
            ->fillForm([
                'canonical_seo_landing_page_id' => $page->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($page->id, $category->fresh()->canonical_seo_landing_page_id);
    }

    public function test_canonical_landing_page_mapping_rejects_draft_pages(): void
    {
        $this->actingAs(User::factory()->create());

        $page = SeoLandingPage::factory()->draft()->create([
            'heading' => 'Draft Landing Page',
            'slug' => 'draft-landing-page',
        ]);

        $category = Category::query()->create([
            'name' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'is_active' => true,
        ]);

        Livewire::test(EditCategory::class, [
            'record' => $category->getRouteKey(),
        ])
            ->fillForm([
                'canonical_seo_landing_page_id' => $page->id,
            ])
            ->call('save')
            ->assertHasFormErrors(['canonical_seo_landing_page_id']);

        $this->assertNull($category->fresh()->canonical_seo_landing_page_id);
    }

    public function test_editing_slug_rebuilds_descendant_paths(): void
    {
        $this->actingAs(User::factory()->create());

        $root = Category::query()->create([
            'name' => 'Gifts for Him',
            'slug' => 'gifts-for-him',
            'full_path' => 'gifts-for-him',
        ]);

        $child = Category::query()->create([
            'parent_id' => $root->id,
            'name' => 'Husband',
            'slug' => 'husband',
            'full_path' => 'gifts-for-him/husband',
        ]);

        Livewire::test(EditCategory::class, [
            'record' => $root->getRouteKey(),
        ])
            ->fillForm([
                'slug' => 'gifts-for-men',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('gifts-for-men', $root->fresh()->full_path);
        $this->assertSame('gifts-for-men/husband', $child->fresh()->full_path);
    }
}
