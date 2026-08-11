<?php

namespace Tests\Unit\Actions;

use App\Actions\Category\RebuildCategoryPathsAction;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RebuildCategoryPathsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_paths_for_arbitrary_depth(): void
    {
        $root = Category::query()->create([
            'name' => 'Gifts for Him',
            'slug' => 'gifts-for-him',
            'full_path' => 'pending',
        ]);

        $child = Category::query()->create([
            'parent_id' => $root->id,
            'name' => 'Gifts for Husband',
            'slug' => 'gifts-for-husband',
            'full_path' => 'pending',
        ]);

        $grandchild = Category::query()->create([
            'parent_id' => $child->id,
            'name' => 'Birthday',
            'slug' => 'birthday',
            'full_path' => 'pending',
        ]);

        app(RebuildCategoryPathsAction::class)->execute($root->fresh());

        $this->assertSame('gifts-for-him', $root->fresh()->full_path);
        $this->assertSame('gifts-for-him/gifts-for-husband', $child->fresh()->full_path);
        $this->assertSame('gifts-for-him/gifts-for-husband/birthday', $grandchild->fresh()->full_path);
    }

    public function test_it_rebuilds_subtree_paths_when_parent_slug_changes(): void
    {
        $root = Category::query()->create([
            'name' => 'Gifts for Him',
            'slug' => 'gifts-for-him',
            'full_path' => 'gifts-for-him',
        ]);

        $child = Category::query()->create([
            'parent_id' => $root->id,
            'name' => 'Gifts for Husband',
            'slug' => 'gifts-for-husband',
            'full_path' => 'gifts-for-him/gifts-for-husband',
        ]);

        $root->update(['slug' => 'gifts-for-men']);

        $this->assertSame('gifts-for-men', $root->fresh()->full_path);
        $this->assertSame('gifts-for-men/gifts-for-husband', $child->fresh()->full_path);
    }

    public function test_it_rebuilds_subtree_paths_when_category_is_reparented(): void
    {
        $rootA = Category::query()->create([
            'name' => 'Root A',
            'slug' => 'root-a',
            'full_path' => 'root-a',
        ]);

        $rootB = Category::query()->create([
            'name' => 'Root B',
            'slug' => 'root-b',
            'full_path' => 'root-b',
        ]);

        $child = Category::query()->create([
            'parent_id' => $rootA->id,
            'name' => 'Child',
            'slug' => 'child',
            'full_path' => 'root-a/child',
        ]);

        $child->update(['parent_id' => $rootB->id]);

        $this->assertSame('root-b/child', $child->fresh()->full_path);
    }
}
