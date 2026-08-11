<?php

namespace Database\Seeders;

use App\Actions\Category\RebuildCategoryPathsAction;
use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $classificationCategories = [
            ['Personalized Gifts', 'personalized-gifts'],
            ['Home & Living', 'home-and-living'],
            ['Electronics', 'electronics'],
            ['Fashion & Accessories', 'fashion-and-accessories'],
            ['Beauty & Grooming', 'beauty-and-grooming'],
            ['Food & Beverages', 'food-and-beverages'],
            ['Books', 'books'],
            ['Toys & Games', 'toys-and-games'],
            ['Wellness', 'wellness'],
        ];

        foreach ($classificationCategories as $sortOrder => [$name, $slug]) {
            $this->seedCategory(null, $name, $slug, $sortOrder + 1);
        }

        $giftsForHim = $this->seedCategory(null, 'Gifts for Him', 'gifts-for-him', 100);
        $this->seedCategory($giftsForHim->id, 'Gifts for Husband', 'gifts-for-husband', 1);

        $birthdayGifts = $this->seedCategory(null, 'Birthday Gifts', 'birthday-gifts', 101);
        $this->seedCategory($birthdayGifts->id, 'Birthday Gifts for Husband', 'birthday-gifts-for-husband', 1);

        Category::query()
            ->whereNull('parent_id')
            ->orderBy('id')
            ->each(fn (Category $root) => app(RebuildCategoryPathsAction::class)->execute($root));
    }

    private function seedCategory(?int $parentId, string $name, string $slug, int $sortOrder): Category
    {
        return Category::query()->updateOrCreate(
            [
                'parent_id' => $parentId,
                'slug' => $slug,
            ],
            [
                'name' => $name,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ],
        );
    }
}
