<?php

namespace Database\Seeders;

use App\Actions\SeoLandingPage\PublishSeoLandingPageAction;
use App\Enums\SeoLandingPageStatus;
use App\Models\Category;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use Illuminate\Database\Seeder;

class SeoLandingPageSeeder extends Seeder
{
    public function run(): void
    {
        $husband = Relationship::query()->where('slug', 'husband')->firstOrFail();
        $birthday = Occasion::query()->where('slug', 'birthday')->firstOrFail();

        $page = SeoLandingPage::query()->updateOrCreate(
            ['slug' => 'birthday-gifts-for-husband'],
            [
                'name' => 'Birthday Gifts for Husband',
                'heading' => 'Birthday Gifts for Husband',
                'relationship_id' => $husband->id,
                'occasion_id' => $birthday->id,
                'recipient_type_id' => null,
                'profession_id' => null,
                'gift_type_id' => null,
                'category_id' => null,
                'budget_range_id' => null,
                'is_indexable' => true,
                'include_in_sitemap' => true,
                'sort_order' => 1,
            ],
        );

        $page->interests()->sync([]);

        if ($page->status !== SeoLandingPageStatus::Published) {
            app(PublishSeoLandingPageAction::class)->execute($page->fresh());
        }

        $parent = Category::query()
            ->where('slug', 'birthday-gifts')
            ->whereNull('parent_id')
            ->firstOrFail();

        $category = Category::query()
            ->where('slug', 'birthday-gifts-for-husband')
            ->where('parent_id', $parent->id)
            ->firstOrFail();

        $category->update([
            'canonical_seo_landing_page_id' => $page->id,
            'is_active' => true,
        ]);
    }
}
