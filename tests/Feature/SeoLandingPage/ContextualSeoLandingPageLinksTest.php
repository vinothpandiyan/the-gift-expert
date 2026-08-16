<?php

namespace Tests\Feature\SeoLandingPage;

use App\Enums\SeoLandingPageStatus;
use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContextualSeoLandingPageLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_page_links_published_indexable_matching_landing_pages(): void
    {
        $husband = $this->relationship('Husband');
        $wife = $this->relationship('Wife');
        $birthday = $this->occasion('Birthday');

        $matching = $this->landingPage([
            'slug' => 'birthday-gifts-for-husband',
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
            'is_indexable' => true,
        ]);

        $draft = SeoLandingPage::factory()->draft()->create([
            'slug' => 'draft-gifts-for-husband',
            'heading' => 'Draft Gifts for Husband',
            'relationship_id' => $husband->id,
        ]);

        $noindex = $this->landingPage([
            'slug' => 'noindex-gifts-for-husband',
            'heading' => 'Noindex Gifts for Husband',
            'relationship_id' => $husband->id,
            'is_indexable' => false,
        ]);

        $unrelated = $this->landingPage([
            'slug' => 'birthday-gifts-for-wife',
            'heading' => 'Birthday Gifts for Wife',
            'relationship_id' => $wife->id,
            'occasion_id' => $birthday->id,
            'is_indexable' => true,
        ]);

        $deleted = $this->landingPage([
            'slug' => 'deleted-gifts-for-husband',
            'heading' => 'Deleted Gifts for Husband',
            'relationship_id' => $husband->id,
            'is_indexable' => true,
        ]);
        $deleted->delete();

        $this->get(DiscoveryUrl::relationship($husband->slug))
            ->assertOk()
            ->assertSee('href="'.DiscoveryUrl::seoLandingPage($matching->slug).'"', false)
            ->assertSee('Birthday Gifts for Husband', false)
            ->assertDontSee('href="'.DiscoveryUrl::seoLandingPage($draft->slug).'"', false)
            ->assertDontSee('Draft Gifts for Husband', false)
            ->assertDontSee('href="'.DiscoveryUrl::seoLandingPage($noindex->slug).'"', false)
            ->assertDontSee('Noindex Gifts for Husband', false)
            ->assertDontSee('href="'.DiscoveryUrl::seoLandingPage($unrelated->slug).'"', false)
            ->assertDontSee('Birthday Gifts for Wife', false)
            ->assertDontSee('Deleted Gifts for Husband', false);
    }

    public function test_occasion_page_links_matching_published_indexable_landing_pages(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $anniversary = $this->occasion('Anniversary');

        $matching = $this->landingPage([
            'slug' => 'birthday-gifts-for-husband',
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
            'is_indexable' => true,
        ]);

        $unrelated = $this->landingPage([
            'slug' => 'anniversary-gifts-for-husband',
            'heading' => 'Anniversary Gifts for Husband',
            'relationship_id' => $husband->id,
            'occasion_id' => $anniversary->id,
            'is_indexable' => true,
        ]);

        $this->get(DiscoveryUrl::occasion($birthday->slug))
            ->assertOk()
            ->assertSee('href="'.DiscoveryUrl::seoLandingPage($matching->slug).'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::seoLandingPage($unrelated->slug).'"', false)
            ->assertDontSee('Anniversary Gifts for Husband', false);
    }

    public function test_category_page_promotes_mapped_child_landing_page_not_category_url(): void
    {
        $husband = $this->relationship('Husband');
        $page = $this->landingPage([
            'slug' => 'birthday-gifts-for-husband',
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $husband->id,
            'is_indexable' => true,
        ]);

        $parent = Category::query()->create([
            'name' => 'Birthday Gifts',
            'slug' => 'birthday-gifts',
            'is_active' => true,
        ]);
        $child = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'is_active' => true,
            'canonical_seo_landing_page_id' => $page->id,
        ]);
        $merchandising = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Visible Merchandising Child',
            'slug' => 'visible-merchandising-child',
            'is_active' => true,
        ]);

        $this->get(DiscoveryUrl::giftIdeasCategory($parent->fresh()->full_path))
            ->assertOk()
            ->assertSee('href="'.DiscoveryUrl::seoLandingPage($page->slug).'"', false)
            ->assertSee('href="'.DiscoveryUrl::giftIdeasCategory($merchandising->fresh()->full_path).'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::giftIdeasCategory($child->fresh()->full_path).'"', false);
    }

    public function test_budget_only_landing_page_is_not_listed_on_a_relationship_page(): void
    {
        $husband = $this->relationship('Husband');
        $range = BudgetRange::query()->create([
            'name' => 'Under ₹500',
            'slug' => 'under-500',
            'max_amount' => '499.99',
            'currency' => 'INR',
        ]);

        $budgetOnly = $this->landingPage([
            'slug' => 'gifts-under-500',
            'heading' => 'Gifts Under 500',
            'relationship_id' => null,
            'budget_range_id' => $range->id,
            'is_indexable' => true,
        ]);

        $this->get(DiscoveryUrl::relationship($husband->slug))
            ->assertOk()
            ->assertDontSee('href="'.DiscoveryUrl::seoLandingPage($budgetOnly->slug).'"', false)
            ->assertDontSee('Gifts Under 500', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function landingPage(array $attributes): SeoLandingPage
    {
        return SeoLandingPage::factory()->published()->create(array_merge([
            'status' => SeoLandingPageStatus::Published,
        ], $attributes));
    }

    private function relationship(string $name): Relationship
    {
        return Relationship::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function occasion(string $name): Occasion
    {
        return Occasion::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }
}
