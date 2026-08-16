<?php

namespace Tests\Feature\Discovery;

use App\Models\Category;
use App\Models\Occasion;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use App\Support\Terminology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GiftIdeasNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_gift_ideas_hub_lists_taxonomy_and_category_urls_not_landing_pages(): void
    {
        $kids = RecipientType::query()->create(['name' => 'Kids', 'slug' => 'kids', 'is_active' => true]);
        $husband = Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true]);
        $birthday = Occasion::query()->create(['name' => 'Birthday', 'slug' => 'birthday', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'Personalized Gifts', 'slug' => 'personalized-gifts', 'is_active' => true]);

        $page = SeoLandingPage::factory()->published()->create([
            'heading' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
            'is_indexable' => true,
        ]);

        $this->get(DiscoveryUrl::giftIdeas())
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.DiscoveryUrl::giftIdeas(absolute: true).'">', false)
            ->assertSee('href="'.DiscoveryUrl::finder().'"', false)
            ->assertSee('href="'.DiscoveryUrl::giftIdeas().'"', false)
            ->assertSee('href="'.DiscoveryUrl::recipientType($kids->slug).'"', false)
            ->assertSee('href="'.DiscoveryUrl::relationship($husband->slug).'"', false)
            ->assertSee('href="'.DiscoveryUrl::occasion($birthday->slug).'"', false)
            ->assertSee('href="'.DiscoveryUrl::giftIdeasCategory($category->fresh()->full_path).'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::seoLandingPage($page->slug).'"', false);
    }

    public function test_primary_navigation_keeps_finder_and_does_not_replace_husband_taxonomy(): void
    {
        Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true]);

        $this->get(DiscoveryUrl::finder())
            ->assertOk()
            ->assertSee('href="'.DiscoveryUrl::finder().'"', false)
            ->assertSee('href="'.DiscoveryUrl::giftIdeas().'"', false)
            ->assertSee(Terminology::giftIdeas(), false);

        $this->get(DiscoveryUrl::relationship('husband'))
            ->assertOk()
            ->assertSee('Relationship', false);
    }
}
