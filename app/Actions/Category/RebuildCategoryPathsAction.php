<?php

namespace App\Actions\Category;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RebuildCategoryPathsAction
{
    /**
     * @return array<string, string> Map of old full_path => new full_path
     */
    public function execute(Category $category): array
    {
        $changes = [];

        DB::transaction(function () use ($category, &$changes): void {
            $this->rebuildNode($category->fresh(['parent']), $changes);
        });

        return $changes;
    }

    public function pathFor(Category $category): string
    {
        if ($category->parent_id === null) {
            return $category->slug;
        }

        $parent = $category->relationLoaded('parent')
            ? $category->parent
            : Category::query()->find($category->parent_id);

        if ($parent === null) {
            return $category->slug;
        }

        return $parent->full_path.'/'.$category->slug;
    }

    /**
     * @param  array<string, string>  $changes
     */
    private function rebuildNode(Category $category, array &$changes): void
    {
        $oldPath = $category->full_path;
        $newPath = $this->pathFor($category);

        if ($oldPath !== $newPath) {
            if ($oldPath !== null && $oldPath !== '') {
                $changes[$oldPath] = $newPath;
            }

            $category->forceFill(['full_path' => $newPath])->saveQuietly();
        }

        $category->children()
            ->orderBy('id')
            ->get()
            ->each(fn (Category $child) => $this->rebuildNode($child->fresh(['parent']), $changes));
    }

    /**
     * @return Collection<int, Category>
     */
    public function descendants(Category $category): Collection
    {
        $descendants = collect();

        $category->children()
            ->orderBy('id')
            ->get()
            ->each(function (Category $child) use ($descendants): void {
                $descendants->push($child);
                $descendants->push(...$this->descendants($child));
            });

        return $descendants;
    }
}
