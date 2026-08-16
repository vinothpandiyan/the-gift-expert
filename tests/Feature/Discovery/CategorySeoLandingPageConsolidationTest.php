<?php

namespace Tests\Feature\Discovery;

use App\Enums\SeoLandingPageStatus;
use App\Models\Category;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorySeoLandingPageConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unmapped_category_url_still_resolves(): void
    {
        $category = Category::query()->create([
            'name' => 'Personalized Gifts',
            'slug' => 'personalized-gifts',
            'is_active' => true,
        ]);

        $this->get(DiscoveryUrl::giftIdeasCategory($category->fresh()->full_path))
            ->assertOk()
            ->assertSee('Personalized Gifts', false);
    }

    public function test_inactive_unmapped_category_still_returns_not_found(): void
    {
        Category::query()->create([
            'name' => 'Hidden',
            'slug' => 'hidden-cat',
            'is_active' => false,
        ]);

        $this->get(DiscoveryUrl::giftIdeasCategory('hidden-cat'))->assertNotFound();
    }

    public function test_mapped_category_url_redirects_to_published_landing_page(): void
    {
        [$category, $page] = $this->mappedCompositeCategory();

        $this->get(DiscoveryUrl::giftIdeasCategory($category->full_path))
            ->assertStatus(301)
            ->assertRedirect(DiscoveryUrl::seoLandingPage($page->slug));
    }

    public function test_historical_category_path_redirects_directly_to_landing_page(): void
    {
        [$category, $page] = $this->mappedCompositeCategory();

        $oldPath = $category->full_path;

        $category->update(['slug' => 'birthday-gifts-for-husbands']);

        $this->assertNotSame($oldPath, $category->fresh()->full_path);

        $this->get(DiscoveryUrl::giftIdeasCategory($oldPath))
            ->assertStatus(301)
            ->assertRedirect(DiscoveryUrl::seoLandingPage($page->slug));

        $this->get(DiscoveryUrl::giftIdeasCategory($category->fresh()->full_path))
            ->assertStatus(301)
            ->assertRedirect(DiscoveryUrl::seoLandingPage($page->slug));
    }

    public function test_unmapped_category_path_redirects_still_target_the_category(): void
    {
        $category = Category::query()->create([
            'name' => 'Gifts for Him',
            'slug' => 'gifts-for-him',
            'is_active' => true,
        ]);

        $category->update(['slug' => 'gifts-for-men']);

        $this->get(DiscoveryUrl::giftIdeasCategory('gifts-for-him'))
            ->assertStatus(301)
            ->assertRedirect(DiscoveryUrl::giftIdeasCategory('gifts-for-men'));
    }

    public function test_draft_landing_page_mapping_does_not_redirect_or_404(): void
    {
        $husband = $this->husband();
        $page = SeoLandingPage::factory()->draft()->create([
            'slug' => 'birthday-gifts-for-husband',
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $husband->id,
        ]);

        $parent = Category::query()->create([
            'name' => 'Birthday Gifts',
            'slug' => 'birthday-gifts',
            'is_active' => true,
        ]);
        $category = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'is_active' => true,
            'canonical_seo_landing_page_id' => $page->id,
        ]);

        $this->get(DiscoveryUrl::giftIdeasCategory($category->fresh()->full_path))
            ->assertOk()
            ->assertSee('Birthday Gifts for Husband', false);
    }

    public function test_relationship_and_occasion_urls_remain_unchanged(): void
    {
        $husband = $this->husband();
        $birthday = $this->birthday();
        $this->mappedCompositeCategory($husband, $birthday);

        $this->get(DiscoveryUrl::relationship($husband->slug))
            ->assertOk()
            ->assertSee('Husband', false)
            ->assertSee('<link rel="canonical" href="'.DiscoveryUrl::relationship($husband->slug, absolute: true).'">', false);

        $this->get(DiscoveryUrl::occasion($birthday->slug))
            ->assertOk()
            ->assertSee('Birthday', false)
            ->assertSee('<link rel="canonical" href="'.DiscoveryUrl::occasion($birthday->slug, absolute: true).'">', false);
    }

    public function test_landing_page_lists_taxonomy_matches_not_copied_category_products(): void
    {
        $husband = $this->husband();
        $birthday = $this->birthday();
        [$category, $page] = $this->mappedCompositeCategory($husband, $birthday);

        $merchandising = Category::query()->create([
            'name' => 'Personalized Gifts',
            'slug' => 'personalized-gifts',
            'is_active' => true,
        ]);

        $matching = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Matching Husband Birthday Gift',
            'slug' => 'matching-husband-birthday',
        ]);
        $matching->categories()->attach($merchandising->id, ['is_primary' => true]);
        $matching->relationships()->attach($husband->id);
        $matching->occasions()->attach($birthday->id);

        $pivotOnly = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Category Pivot Only Gift',
            'slug' => 'category-pivot-only',
        ]);
        $category->products()->attach($pivotOnly->id);

        $this->assertSame(1, $category->products()->count());

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.DiscoveryUrl::seoLandingPage($page->slug, absolute: true).'">', false)
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('Matching Husband Birthday Gift', false)
            ->assertDontSee('Category Pivot Only Gift', false);

        $this->assertSame(1, $category->fresh()->products()->count());
        $this->assertTrue($category->products()->whereKey($pivotOnly->id)->exists());
        $this->assertFalse($matching->categories()->whereKey($category->id)->exists());
    }

    public function test_parent_browse_hides_children_mapped_to_a_published_landing_page(): void
    {
        [$category] = $this->mappedCompositeCategory();
        $parent = $category->parent;

        $visibleChild = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Visible Merchandising Child',
            'slug' => 'visible-merchandising-child',
            'is_active' => true,
        ]);

        $this->get(DiscoveryUrl::giftIdeasCategory($parent->fresh()->full_path))
            ->assertOk()
            ->assertSee('Visible Merchandising Child', false)
            ->assertSee(DiscoveryUrl::giftIdeasCategory($visibleChild->fresh()->full_path), false)
            ->assertSee(DiscoveryUrl::seoLandingPage('birthday-gifts-for-husband'), false)
            ->assertDontSee(DiscoveryUrl::giftIdeasCategory($category->full_path), false);
    }

    /**
     * @return array{0: Category, 1: SeoLandingPage}
     */
    private function mappedCompositeCategory(?Relationship $husband = null, ?Occasion $birthday = null): array
    {
        $husband ??= $this->husband();
        $birthday ??= $this->birthday();

        $page = SeoLandingPage::factory()->published()->create([
            'name' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'heading' => 'Birthday Gifts for Husband',
            'status' => SeoLandingPageStatus::Published,
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
            'category_id' => null,
            'is_indexable' => true,
            'include_in_sitemap' => true,
        ]);

        $parent = Category::query()->create([
            'name' => 'Birthday Gifts',
            'slug' => 'birthday-gifts',
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'is_active' => true,
            'canonical_seo_landing_page_id' => $page->id,
        ]);

        return [$category->fresh(), $page];
    }

    private function husband(): Relationship
    {
        return Relationship::query()->firstOrCreate(
            ['slug' => 'husband'],
            [
                'name' => 'Husband',
                'is_active' => true,
            ],
        );
    }

    private function birthday(): Occasion
    {
        return Occasion::query()->firstOrCreate(
            ['slug' => 'birthday'],
            [
                'name' => 'Birthday',
                'is_active' => true,
            ],
        );
    }
}
