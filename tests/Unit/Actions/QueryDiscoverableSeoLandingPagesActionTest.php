<?php

namespace Tests\Unit\Actions;

use App\Actions\SeoLandingPage\QueryDiscoverableSeoLandingPagesAction;
use App\Models\Category;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueryDiscoverableSeoLandingPagesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_only_published_indexable_matches_for_a_relationship(): void
    {
        $husband = Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true]);
        $wife = Relationship::query()->create(['name' => 'Wife', 'slug' => 'wife', 'is_active' => true]);

        $matching = SeoLandingPage::factory()->published()->create([
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $husband->id,
            'is_indexable' => true,
        ]);
        SeoLandingPage::factory()->draft()->create([
            'heading' => 'Draft',
            'relationship_id' => $husband->id,
        ]);
        SeoLandingPage::factory()->published()->create([
            'heading' => 'Wife page',
            'relationship_id' => $wife->id,
            'is_indexable' => true,
        ]);

        $pages = app(QueryDiscoverableSeoLandingPagesAction::class)
            ->execute(['relationship_id' => $husband->id]);

        $this->assertSame([$matching->id], $pages->pluck('id')->all());
    }

    public function test_for_category_includes_canonical_child_landing_pages_not_unrelated_filters(): void
    {
        $husband = Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true]);
        $page = SeoLandingPage::factory()->published()->create([
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $husband->id,
            'category_id' => null,
            'is_indexable' => true,
        ]);
        $filterPage = SeoLandingPage::factory()->published()->create([
            'heading' => 'Personalized collection',
            'relationship_id' => $husband->id,
            'is_indexable' => true,
        ]);

        $parent = Category::query()->create(['name' => 'Birthday Gifts', 'slug' => 'birthday-gifts', 'is_active' => true]);
        Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'is_active' => true,
            'canonical_seo_landing_page_id' => $page->id,
        ]);
        $personalized = Category::query()->create(['name' => 'Personalized', 'slug' => 'personalized', 'is_active' => true]);
        $filterPage->update(['category_id' => $personalized->id]);

        $pages = app(QueryDiscoverableSeoLandingPagesAction::class)->forCategory($parent);

        $this->assertSame([$page->id], $pages->pluck('id')->all());
    }

    public function test_it_returns_nothing_without_a_filter(): void
    {
        SeoLandingPage::factory()->published()->create(['is_indexable' => true]);

        $pages = app(QueryDiscoverableSeoLandingPagesAction::class)->execute([]);

        $this->assertTrue($pages->isEmpty());
    }

    public function test_it_excludes_pages_after_indexability_is_turned_off(): void
    {
        $husband = Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true]);

        $page = SeoLandingPage::factory()->published()->create([
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $husband->id,
            'is_indexable' => true,
        ]);

        $this->assertSame(
            [$page->id],
            app(QueryDiscoverableSeoLandingPagesAction::class)
                ->execute(['relationship_id' => $husband->id])
                ->pluck('id')
                ->all(),
        );

        $page->update(['is_indexable' => false]);

        $this->assertTrue(
            app(QueryDiscoverableSeoLandingPagesAction::class)
                ->execute(['relationship_id' => $husband->id])
                ->isEmpty(),
        );
    }
}
