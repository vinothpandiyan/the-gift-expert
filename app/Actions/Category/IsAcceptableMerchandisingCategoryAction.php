<?php

namespace App\Actions\Category;

use App\Models\Category;
use App\Models\Occasion;
use App\Models\RecipientType;
use App\Models\Relationship;

class IsAcceptableMerchandisingCategoryAction
{
    /**
     * @var list<string>|null
     */
    private ?array $occasionSlugs = null;

    /**
     * @var list<string>|null
     */
    private ?array $intentSlugs = null;

    public function execute(int $categoryId): bool
    {
        $category = Category::query()->whereKey($categoryId)->first();

        if (! $category instanceof Category || $category->is_active !== true) {
            return false;
        }

        if (! is_string($category->full_path) || trim($category->full_path) === '') {
            return false;
        }

        if ($category->canonical_seo_landing_page_id !== null) {
            return false;
        }

        $current = $category;

        while ($current->parent_id !== null) {
            $parent = Category::withTrashed()->find($current->parent_id);

            if (! $parent instanceof Category) {
                break;
            }

            if ($parent->canonical_seo_landing_page_id !== null) {
                return false;
            }

            $current = $parent;
        }

        $slug = $category->slug;

        if ($slug === 'personalized-gifts' || str_starts_with((string) $slug, 'gifts-for-')) {
            return false;
        }

        foreach ($this->occasionSlugs() as $occasionSlug) {
            if ($slug === $occasionSlug.'-gifts') {
                return false;
            }
        }

        return ! in_array($slug, $this->intentSlugs(), true);
    }

    /**
     * @return list<string>
     */
    private function occasionSlugs(): array
    {
        if ($this->occasionSlugs === null) {
            $this->occasionSlugs = Occasion::query()
                ->where('is_active', true)
                ->pluck('slug')
                ->filter(fn (mixed $slug): bool => is_string($slug) && $slug !== '')
                ->values()
                ->all();
        }

        return $this->occasionSlugs;
    }

    /**
     * @return list<string>
     */
    private function intentSlugs(): array
    {
        if ($this->intentSlugs === null) {
            $this->intentSlugs = Relationship::query()->where('is_active', true)->pluck('slug')
                ->merge(RecipientType::query()->where('is_active', true)->pluck('slug'))
                ->filter(fn (mixed $slug): bool => is_string($slug) && $slug !== '')
                ->values()
                ->all();
        }

        return $this->intentSlugs;
    }
}
