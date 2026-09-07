<?php

namespace Database\Seeders;

use App\Actions\Category\RebuildCategoryPathsAction;
use App\Models\Category;
use App\Models\CategoryPathRedirect;
use App\Support\DiscoveryUrl;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $classificationCategories = [
            ['Home & Living', 'home-and-living', 2],
            ['Electronics', 'electronics', 3],
            ['Fashion & Accessories', 'fashion-and-accessories', 4],
            ['Beauty & Grooming', 'beauty-and-grooming', 5],
            ['Food & Beverages', 'food-and-beverages', 6],
            ['Books', 'books', 7],
            ['Toys & Games', 'toys-and-games', 8],
            ['Wellness', 'wellness', 9],
            ['Stationery & Office', 'stationery-and-office', 10],
            ['Spiritual & Pooja', 'spiritual-and-pooja', 11],
            ['Sports & Outdoors', 'sports-and-outdoors', 12],
        ];

        foreach ($classificationCategories as [$name, $slug, $sortOrder]) {
            $this->seedCategory(null, $name, $slug, $sortOrder);
        }

        // TODO: retire this inactive Personalized Gifts Category after confirming no Product still
        // references it. Public discovery and applicability use GiftType `personalized-gifts`.
        $this->seedCategory(null, 'Personalized Gifts', 'personalized-gifts', 1, active: false);

        $home = Category::query()
            ->where('slug', 'home-and-living')
            ->whereNull('parent_id')
            ->firstOrFail();
        $fashion = Category::query()
            ->where('slug', 'fashion-and-accessories')
            ->whereNull('parent_id')
            ->firstOrFail();

        $this->seedCategory($home->id, 'Kitchen & Dining', 'kitchen-and-dining', 1);
        $this->seedCategory($fashion->id, 'Jewellery', 'jewellery', 1);

        $giftsForHim = $this->seedCategory(null, 'Gifts for Him', 'gifts-for-him', 100, active: false);
        $this->seedCategory($giftsForHim->id, 'Gifts for Husband', 'gifts-for-husband', 1, active: false);

        $birthdayGifts = $this->seedCategory(null, 'Birthday Gifts', 'birthday-gifts', 101, active: false);
        $this->seedCategory($birthdayGifts->id, 'Birthday Gifts for Husband', 'birthday-gifts-for-husband', 1, active: false);

        Category::query()
            ->whereNull('parent_id')
            ->orderBy('id')
            ->each(fn (Category $root) => app(RebuildCategoryPathsAction::class)->execute($root));

        $this->seedLegacyPathRedirects();
    }

    private function seedCategory(?int $parentId, string $name, string $slug, int $sortOrder, bool $active = true): Category
    {
        return Category::query()->updateOrCreate(
            [
                'parent_id' => $parentId,
                'slug' => $slug,
            ],
            [
                'name' => $name,
                'sort_order' => $sortOrder,
                'is_active' => $active,
            ],
        );
    }

    private function seedLegacyPathRedirects(): void
    {
        $this->seedPathRedirect('gifts-for-him', DiscoveryUrl::giftIdeas());
        $this->seedPathRedirect('gifts-for-him/gifts-for-husband', DiscoveryUrl::relationship('husband'));
        $this->seedPathRedirect('birthday-gifts', DiscoveryUrl::occasion('birthday'));
        $this->seedPathRedirect('personalized-gifts', DiscoveryUrl::giftType('personalized-gifts'));
    }

    private function seedPathRedirect(string $fromPath, string $toUrl): void
    {
        CategoryPathRedirect::query()->updateOrCreate(
            ['from_path' => $fromPath],
            [
                'to_path' => null,
                'to_url' => $toUrl,
            ],
        );
    }
}
