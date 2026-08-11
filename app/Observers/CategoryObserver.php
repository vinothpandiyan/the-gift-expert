<?php

namespace App\Observers;

use App\Actions\Category\RebuildCategoryPathsAction;
use App\Actions\Category\RecordCategoryPathRedirectsAction;
use App\Models\Category;

class CategoryObserver
{
    public function saving(Category $category): void
    {
        Category::assertValidParent($category->parent_id, $category->id);
    }

    public function creating(Category $category): void
    {
        if (blank($category->full_path)) {
            $category->full_path = app(RebuildCategoryPathsAction::class)->pathFor($category);
        }
    }

    public function updated(Category $category): void
    {
        if (! $category->wasChanged(['slug', 'parent_id'])) {
            return;
        }

        $changes = app(RebuildCategoryPathsAction::class)->execute($category);

        app(RecordCategoryPathRedirectsAction::class)->execute($changes);
    }
}
