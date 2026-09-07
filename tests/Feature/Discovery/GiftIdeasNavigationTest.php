<?php

namespace Tests\Feature\Discovery;

use App\DiscoveryListing\DiscoveryListingContext;
use App\Livewire\GiftListing;
use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use App\Support\Terminology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GiftIdeasNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_gift_ideas_hub_lists_taxonomy_and_category_urls_not_landing_pages(): void
    {
        $kids = RecipientType::query()->create(['name' => 'Kids', 'slug' => 'kids', 'is_active' => true]);
        $husband = Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true]);
        $birthday = Occasion::query()->create(['name' => 'Birthday', 'slug' => 'birthday', 'is_active' => true]);
        $coffee = Interest::query()->create(['name' => 'Coffee', 'slug' => 'coffee', 'is_active' => true]);
        $doctor = Profession::query()->create(['name' => 'Doctor', 'slug' => 'doctor', 'is_active' => true]);
        $giftCards = GiftType::query()->create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'Personalized Gifts', 'slug' => 'personalized-gifts', 'is_active' => true]);
        $child = Category::query()->create([
            'name' => 'Photo Frames',
            'slug' => 'photo-frames',
            'parent_id' => $category->id,
            'is_active' => true,
        ]);

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
            ->assertSee('href="'.DiscoveryUrl::interest($coffee->slug).'"', false)
            ->assertSee('href="'.DiscoveryUrl::profession($doctor->slug).'"', false)
            ->assertSee('href="'.DiscoveryUrl::giftType($giftCards->slug).'"', false)
            ->assertSee('href="'.DiscoveryUrl::giftIdeasCategory($category->fresh()->full_path).'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::giftIdeasCategory($child->fresh()->full_path).'"', false)
            ->assertSee('By recipient', false)
            ->assertSee('By occasion', false)
            ->assertSee('By interest', false)
            ->assertSee('By profession', false)
            ->assertSee('By gift type', false)
            ->assertSee('Categories', false)
            ->assertSee('Doctor', false)
            ->assertSee('Gift Cards', false)
            ->assertDontSee('href="'.DiscoveryUrl::seoLandingPage($page->slug).'"', false)
            ->assertDontSee('calculator', false)
            ->assertDontSee('/budget', false)
            ->assertDontSee('href="/blog', false);
    }

    public function test_gift_ideas_hub_omits_inactive_and_soft_deleted_professions_and_gift_types(): void
    {
        $activeProfession = Profession::query()->create(['name' => 'Doctor', 'slug' => 'doctor', 'is_active' => true]);
        $inactiveProfession = Profession::query()->create(['name' => 'Teacher', 'slug' => 'teacher', 'is_active' => false]);
        $deletedProfession = Profession::query()->create(['name' => 'Engineer', 'slug' => 'engineer', 'is_active' => true]);
        $deletedProfession->delete();

        $activeGiftType = GiftType::query()->create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'is_active' => true]);
        $inactiveGiftType = GiftType::query()->create(['name' => 'Subscriptions', 'slug' => 'subscriptions', 'is_active' => false]);
        $deletedGiftType = GiftType::query()->create(['name' => 'Online Courses', 'slug' => 'online-courses', 'is_active' => true]);
        $deletedGiftType->delete();

        $this->get(DiscoveryUrl::giftIdeas())
            ->assertOk()
            ->assertSee('href="'.DiscoveryUrl::profession($activeProfession->slug).'"', false)
            ->assertSee('href="'.DiscoveryUrl::giftType($activeGiftType->slug).'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::profession($inactiveProfession->slug).'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::profession($deletedProfession->slug).'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::giftType($inactiveGiftType->slug).'"', false)
            ->assertDontSee('href="'.DiscoveryUrl::giftType($deletedGiftType->slug).'"', false)
            ->assertDontSee('Teacher', false)
            ->assertDontSee('Engineer', false)
            ->assertDontSee('Subscriptions', false)
            ->assertDontSee('Online Courses', false);
    }

    public function test_primary_navigation_keeps_finder_and_does_not_replace_husband_taxonomy(): void
    {
        Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true]);

        $html = $this->get(DiscoveryUrl::finder())
            ->assertOk()
            ->assertSee('Find a Gift', false)
            ->assertSee('href="'.DiscoveryUrl::finder().'"', false)
            ->assertSee('aria-label="Open menu"', false)
            ->getContent();

        $footer = $this->htmlFragment($html, 'footer');

        $this->assertStringContainsString('href="'.DiscoveryUrl::finder().'"', $footer);
        $this->assertStringContainsString('href="'.DiscoveryUrl::giftIdeas().'"', $footer);
        $this->assertStringContainsString(Terminology::giftIdeas(), $footer);

        $this->get(DiscoveryUrl::relationship('husband'))
            ->assertOk()
            ->assertSee('Relationship', false);
    }

    public function test_unfiltered_hub_is_not_a_product_listing(): void
    {
        Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true]);
        GiftCatalogTestHelpers::publishedGift(['name' => 'Catalog Dump Gift', 'slug' => 'catalog-dump-gift']);

        $this->get(DiscoveryUrl::giftIdeas())
            ->assertOk()
            ->assertSee('By recipient', false)
            ->assertDontSee('Narrow it down', false)
            ->assertDontSee('Catalog Dump Gift', false)
            ->assertSee('<meta name="robots" content="index, follow">', false);
    }

    public function test_valid_budget_query_renders_gift_listing_and_is_noindex(): void
    {
        BudgetRange::query()->create([
            'name' => '₹1,000–₹2,500',
            'slug' => '1000-2500',
            'min_amount' => '1000.00',
            'max_amount' => '2500.00',
            'currency' => 'INR',
            'is_active' => true,
        ]);

        $inRange = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Mid Wallet',
            'slug' => 'mid-wallet',
            'price_amount' => '1499.00',
        ]);
        GiftCatalogTestHelpers::publishedGift([
            'name' => 'Luxury Watch',
            'slug' => 'luxury-watch',
            'price_amount' => '8000.00',
        ]);

        $this->get(DiscoveryUrl::giftIdeasQuery(['budget' => '1000-2500']))
            ->assertOk()
            ->assertSee('Narrow it down', false)
            ->assertSee('Mid Wallet', false)
            ->assertDontSee('Luxury Watch', false)
            ->assertDontSee('By recipient', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<link rel="canonical" href="'.DiscoveryUrl::giftIdeas(absolute: true).'">', false)
            ->assertDontSee('href="'.DiscoveryUrl::affiliateOut($inRange->affiliateLinks->first()->uuid).'"', false);
    }

    public function test_invalid_budget_query_keeps_the_hub_and_is_noindex(): void
    {
        Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true]);

        $this->get(DiscoveryUrl::giftIdeas().'?budget=not-a-real-budget')
            ->assertOk()
            ->assertSee('By recipient', false)
            ->assertDontSee('Narrow it down', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<link rel="canonical" href="'.DiscoveryUrl::giftIdeas(absolute: true).'">', false);
    }

    public function test_clearing_budget_on_gift_ideas_listing_returns_to_the_hub(): void
    {
        $budget = BudgetRange::query()->create([
            'name' => 'Under ₹500',
            'slug' => 'under-500',
            'min_amount' => null,
            'max_amount' => '499.99',
            'currency' => 'INR',
            'is_active' => true,
        ]);

        Livewire::withQueryParams(['budget' => $budget->slug])
            ->test(GiftListing::class, [
                'context' => DiscoveryListingContext::forGiftIdeas()->toArray(),
            ])
            ->assertSet('budget', 'under-500')
            ->call('clearFilters')
            ->assertRedirect(DiscoveryUrl::giftIdeas());
    }

    private function htmlFragment(string $html, string $tag): string
    {
        if (preg_match('/<'.$tag.'\b[^>]*>.*<\/'.$tag.'>/is', $html, $matches) !== 1) {
            $this->fail("Missing <{$tag}> in the response.");
        }

        return $matches[0];
    }
}
