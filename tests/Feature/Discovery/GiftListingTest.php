<?php

namespace Tests\Feature\Discovery;

use App\DiscoveryListing\DiscoveryListingContext;
use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Livewire\GiftListing;
use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Models\TaxonomyApplicabilityRule;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class GiftListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_listing_renders_published_gifts_and_hides_drafts(): void
    {
        $husband = $this->relationship('Husband');
        $published = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Travel Organiser',
            'slug' => 'travel-organiser',
            'short_description' => 'Great for someone who travels frequently.',
            'price_amount' => '1499.00',
        ]);
        $draft = Product::factory()->draft()->create(['name' => 'Secret Draft', 'slug' => 'secret-draft']);

        $published->relationships()->attach($husband);
        $draft->relationships()->attach($husband);

        $this->get(DiscoveryUrl::relationship('husband'))
            ->assertOk()
            ->assertSee('Gifts for Husband', false)
            ->assertSee('Travel Organiser', false)
            ->assertSee('Great for someone who travels frequently.', false)
            ->assertSee('Around ₹1,499', false)
            ->assertSee('View gift', false)
            ->assertSee('Filters', false)
            ->assertDontSee('Secret Draft', false);
    }

    public function test_fixed_relationship_context_is_respected(): void
    {
        $husband = $this->relationship('Husband');
        $wife = $this->relationship('Wife');

        $husbandGift = GiftCatalogTestHelpers::publishedGift(['name' => 'Husband Watch', 'slug' => 'husband-watch']);
        $wifeGift = GiftCatalogTestHelpers::publishedGift(['name' => 'Wife Necklace', 'slug' => 'wife-necklace']);
        $husbandGift->relationships()->attach($husband);
        $wifeGift->relationships()->attach($wife);

        $this->get(DiscoveryUrl::relationship('husband'))
            ->assertOk()
            ->assertSee('Husband Watch', false)
            ->assertDontSee('Wife Necklace', false);
    }

    public function test_occasion_filter_narrows_relationship_listing(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $anniversary = $this->occasion('Anniversary');

        $birthdayGift = GiftCatalogTestHelpers::publishedGift(['name' => 'Birthday Mug', 'slug' => 'birthday-mug']);
        $anniversaryGift = GiftCatalogTestHelpers::publishedGift(['name' => 'Anniversary Lamp', 'slug' => 'anniversary-lamp']);
        $birthdayGift->relationships()->attach($husband);
        $birthdayGift->occasions()->attach($birthday);
        $anniversaryGift->relationships()->attach($husband);
        $anniversaryGift->occasions()->attach($anniversary);

        $this->get(DiscoveryUrl::relationship('husband').'?occasion=birthday')
            ->assertOk()
            ->assertSee('Birthday Mug', false)
            ->assertDontSee('Anniversary Lamp', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<link rel="canonical" href="'.DiscoveryUrl::relationship('husband', absolute: true).'">', false);
    }

    public function test_multiple_occasions_or_within_dimension(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $anniversary = $this->occasion('Anniversary');

        $birthdayGift = GiftCatalogTestHelpers::publishedGift(['name' => 'Birthday Mug', 'slug' => 'birthday-mug']);
        $anniversaryGift = GiftCatalogTestHelpers::publishedGift(['name' => 'Anniversary Lamp', 'slug' => 'anniversary-lamp']);
        $other = GiftCatalogTestHelpers::publishedGift(['name' => 'Housewarming Plant', 'slug' => 'housewarming-plant']);
        $housewarming = $this->occasion('Housewarming');

        $birthdayGift->relationships()->attach($husband);
        $birthdayGift->occasions()->attach($birthday);
        $anniversaryGift->relationships()->attach($husband);
        $anniversaryGift->occasions()->attach($anniversary);
        $other->relationships()->attach($husband);
        $other->occasions()->attach($housewarming);

        $this->get(DiscoveryUrl::relationship('husband').'?occasion=anniversary,birthday')
            ->assertOk()
            ->assertSee('Birthday Mug', false)
            ->assertSee('Anniversary Lamp', false)
            ->assertDontSee('Housewarming Plant', false);
    }

    public function test_budget_filter_uses_database_range(): void
    {
        $husband = $this->relationship('Husband');
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
        $expensive = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Luxury Watch',
            'slug' => 'luxury-watch',
            'price_amount' => '8000.00',
        ]);
        $inRange->relationships()->attach($husband);
        $expensive->relationships()->attach($husband);

        $this->get(DiscoveryUrl::relationship('husband').'?budget=1000-2500')
            ->assertOk()
            ->assertSee('Mid Wallet', false)
            ->assertDontSee('Luxury Watch', false);
    }

    public function test_invalid_filter_slug_is_ignored(): void
    {
        $husband = $this->relationship('Husband');
        $gift = GiftCatalogTestHelpers::publishedGift(['name' => 'Safe Gift', 'slug' => 'safe-gift']);
        $gift->relationships()->attach($husband);

        $this->get(DiscoveryUrl::relationship('husband').'?occasion=not-a-real-occasion')
            ->assertOk()
            ->assertSee('Safe Gift', false);
    }

    public function test_livewire_can_clear_filters(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $gift = GiftCatalogTestHelpers::publishedGift(['name' => 'Clearable Gift', 'slug' => 'clearable-gift']);
        $gift->relationships()->attach($husband);
        $gift->occasions()->attach($birthday);

        Livewire::withQueryParams(['occasion' => 'birthday'])
            ->test(GiftListing::class, [
                'context' => DiscoveryListingContext::forRelationship($husband)->toArray(),
            ])
            ->assertSet('occasion', 'birthday')
            ->call('clearFilters')
            ->assertSet('occasion', '')
            ->assertSet('page', 1);
    }

    public function test_price_sort_orders_low_to_high(): void
    {
        $husband = $this->relationship('Husband');
        $cheap = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Cheap Gift',
            'slug' => 'cheap-gift',
            'price_amount' => '199.00',
        ]);
        $costly = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Costly Gift',
            'slug' => 'costly-gift',
            'price_amount' => '5000.00',
        ]);
        $cheap->relationships()->attach($husband);
        $costly->relationships()->attach($husband);

        $html = $this->get(DiscoveryUrl::relationship('husband').'?sort=price_asc')
            ->assertOk()
            ->getContent();

        $this->assertLessThan(strpos($html, 'Costly Gift'), strpos($html, 'Cheap Gift'));
    }

    public function test_newest_sort_puts_later_published_first(): void
    {
        $husband = $this->relationship('Husband');
        $older = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Older Gift',
            'slug' => 'older-gift',
            'published_at' => now()->subDays(5),
        ]);
        $newer = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Newer Gift',
            'slug' => 'newer-gift',
            'published_at' => now()->subDay(),
        ]);
        $older->relationships()->attach($husband);
        $newer->relationships()->attach($husband);

        $html = $this->get(DiscoveryUrl::relationship('husband').'?sort=newest')
            ->assertOk()
            ->getContent();

        $this->assertLessThan(strpos($html, 'Older Gift'), strpos($html, 'Newer Gift'));
    }

    public function test_empty_filtered_state_shows_finder_cta(): void
    {
        $husband = $this->relationship('Husband');
        $this->occasion('Birthday');
        $gift = GiftCatalogTestHelpers::publishedGift(['name' => 'Unfiltered Gift', 'slug' => 'unfiltered-gift']);
        $gift->relationships()->attach($husband);

        $this->get(DiscoveryUrl::relationship('husband').'?occasion=birthday')
            ->assertOk()
            ->assertSee('No gift ideas match all those filters.', false)
            ->assertSee('Try Gift Finder', false)
            ->assertSee('Browse Gift Ideas', false)
            ->assertSee(DiscoveryUrl::finder(), false);
    }

    public function test_gift_card_handles_null_image_and_price(): void
    {
        $product = Product::factory()->published()->create([
            'name' => 'Bare Gift',
            'slug' => 'bare-gift',
            'price_amount' => null,
            'short_description' => null,
        ]);

        $html = $this->blade(
            '<x-gift-card :product="$product" />',
            ['product' => $product->load(['images', 'affiliateLinks.merchant', 'categories'])],
        );

        $this->assertStringContainsString('Bare Gift', $html);
        $this->assertStringContainsString('Image coming soon', $html);
        $this->assertStringContainsString('View gift', $html);
        $this->assertStringContainsString('aspect-square', $html);
        $this->assertStringContainsString('p-1.5', $html);
        $this->assertStringNotContainsString('object-cover', $html);
        $this->assertStringNotContainsString('aspect-4/3', $html);
        $this->assertStringNotContainsString('Around ₹', $html);
    }

    public function test_gift_card_image_uses_contain_without_cover_cropping(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Framed Print',
            'slug' => 'framed-print',
        ]);

        $html = $this->blade(
            '<x-gift-card :product="$product" />',
            ['product' => $product->fresh(['images', 'affiliateLinks.merchant', 'categories'])],
        );

        $this->assertStringContainsString('aspect-square', $html);
        $this->assertStringContainsString('object-contain', $html);
        $this->assertStringContainsString('p-1.5', $html);
        $this->assertStringNotContainsString('object-cover', $html);
        $this->assertStringContainsString('View gift', $html);
    }

    public function test_personalized_badge_uses_existing_category(): void
    {
        $category = Category::query()->create([
            'name' => 'Personalized Gifts',
            'slug' => 'personalized-gifts',
            'is_active' => true,
        ]);
        $product = GiftCatalogTestHelpers::publishedGift(['name' => 'Custom Frame', 'slug' => 'custom-frame']);
        $product->categories()->attach($category->id, ['is_primary' => true]);

        $html = $this->blade(
            '<x-gift-card :product="$product" />',
            ['product' => $product->fresh(['images', 'affiliateLinks.merchant', 'categories'])],
        );

        $this->assertStringContainsString('Personalized', $html);
        $this->assertStringNotContainsString('Great Match', $html);
    }

    public function test_seo_landing_keeps_hard_filters_when_user_adds_interest(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $travel = Interest::query()->create(['name' => 'Travel', 'slug' => 'travel', 'is_active' => true]);
        $coffee = Interest::query()->create(['name' => 'Coffee', 'slug' => 'coffee', 'is_active' => true]);

        $matching = GiftCatalogTestHelpers::publishedGift(['name' => 'Travel Organiser', 'slug' => 'lp-travel']);
        $matching->relationships()->attach($husband);
        $matching->occasions()->attach($birthday);
        $matching->interests()->attach($travel);

        $withoutInterest = GiftCatalogTestHelpers::publishedGift(['name' => 'Generic Husband Birthday', 'slug' => 'lp-generic']);
        $withoutInterest->relationships()->attach($husband);
        $withoutInterest->occasions()->attach($birthday);
        $withoutInterest->interests()->attach($coffee);

        $page = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-husband',
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
            'is_indexable' => true,
        ]);

        $this->get(DiscoveryUrl::seoLandingPage($page->slug).'?interest=travel')
            ->assertOk()
            ->assertSee('Travel Organiser', false)
            ->assertDontSee('Generic Husband Birthday', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<link rel="canonical" href="'.DiscoveryUrl::seoLandingPage($page->slug, absolute: true).'">', false);
    }

    public function test_listing_query_count_does_not_grow_with_more_gifts(): void
    {
        $husband = $this->relationship('Husband');

        foreach (range(1, 3) as $index) {
            $gift = GiftCatalogTestHelpers::publishedGift([
                'name' => "N1 Gift {$index}",
                'slug' => "n1-gift-{$index}",
            ]);
            $gift->relationships()->attach($husband);
        }

        $this->get(DiscoveryUrl::relationship('husband'))->assertOk();

        $first = $this->countQueries(fn () => $this->get(DiscoveryUrl::relationship('husband'))->assertOk());

        foreach (range(4, 9) as $index) {
            $gift = GiftCatalogTestHelpers::publishedGift([
                'name' => "N1 Gift {$index}",
                'slug' => "n1-gift-{$index}",
            ]);
            $gift->relationships()->attach($husband);
        }

        $second = $this->countQueries(fn () => $this->get(DiscoveryUrl::relationship('husband'))->assertOk());

        $this->assertSame($first, $second);
        $this->assertLessThanOrEqual(80, $first);
    }

    public function test_occasion_listing_reuses_the_same_component(): void
    {
        $birthday = $this->occasion('Birthday');
        $gift = GiftCatalogTestHelpers::publishedGift(['name' => 'Party Hat', 'slug' => 'party-hat']);
        $gift->occasions()->attach($birthday);

        $this->get(DiscoveryUrl::occasion('birthday'))
            ->assertOk()
            ->assertSee('Birthday Gifts', false)
            ->assertSee('Party Hat', false)
            ->assertSee('Filters', false);
    }

    public function test_husband_listing_hides_invalid_and_zero_count_filter_options(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $anniversary = $this->occasion('Anniversary');
        $babyShower = $this->occasion('Baby Shower');
        $raksha = $this->occasion('Raksha Bandhan');
        $tech = Interest::query()->create(['name' => 'Tech & Gadgets', 'slug' => 'technology', 'is_active' => true]);
        $experience = GiftType::query()->create([
            'name' => 'Experience Gifts',
            'slug' => 'experience-gifts',
            'is_active' => true,
        ]);

        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => TaxonomyDimension::Relationship,
            'source_id' => $husband->id,
            'target_dimension' => TaxonomyDimension::Occasion,
            'target_id' => $babyShower->id,
            'effect' => TaxonomyApplicabilityEffect::Exclude,
            'reason' => 'test',
            'is_active' => true,
        ]);
        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => TaxonomyDimension::Occasion,
            'source_id' => $raksha->id,
            'target_dimension' => TaxonomyDimension::Relationship,
            'target_id' => $this->relationship('Brother')->id,
            'effect' => TaxonomyApplicabilityEffect::Allow,
            'reason' => 'test',
            'is_active' => true,
        ]);

        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Birthday Tech', 'slug' => 'birthday-tech'],
            ['relationships' => $husband, 'occasions' => $birthday, 'interests' => $tech],
        );
        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Anniversary Tech', 'slug' => 'anniversary-tech'],
            ['relationships' => $husband, 'occasions' => $anniversary, 'interests' => $tech],
        );
        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Bad Raksha Tag', 'slug' => 'bad-raksha'],
            ['relationships' => $husband, 'occasions' => $raksha],
        );

        $html = $this->get(DiscoveryUrl::relationship('husband'))
            ->assertOk()
            ->assertSee('Birthday', false)
            ->assertSee('Anniversary', false)
            ->assertSee('(1)', false)
            ->assertDontSee('Baby Shower', false)
            ->assertDontSee('Raksha Bandhan', false)
            ->assertDontSee('Experience Gifts', false)
            ->getContent();

        $this->assertStringNotContainsString($experience->name.' (', $html);
    }

    public function test_disjunctive_occasion_counts_remain_after_selecting_birthday(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $anniversary = $this->occasion('Anniversary');
        $tech = Interest::query()->create(['name' => 'Tech & Gadgets', 'slug' => 'technology', 'is_active' => true]);

        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Birthday Tech', 'slug' => 'birthday-tech-2'],
            ['relationships' => $husband, 'occasions' => $birthday, 'interests' => $tech],
        );
        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Anniversary Tech', 'slug' => 'anniversary-tech-2'],
            ['relationships' => $husband, 'occasions' => $anniversary, 'interests' => $tech],
        );

        $this->get(DiscoveryUrl::relationship('husband').'?occasion=birthday&interest=technology')
            ->assertOk()
            ->assertSee('Birthday', false)
            ->assertSee('Anniversary', false)
            ->assertSee('(1)', false);
    }

    public function test_invalid_selected_occasion_is_cleared_when_relationship_changes(): void
    {
        $husband = $this->relationship('Husband');
        $sister = $this->relationship('Sister');
        $bridalShower = $this->occasion('Bridal Shower');
        $this->occasion('Birthday');

        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => TaxonomyDimension::Occasion,
            'source_id' => $bridalShower->id,
            'target_dimension' => TaxonomyDimension::Relationship,
            'target_id' => $sister->id,
            'effect' => TaxonomyApplicabilityEffect::Allow,
            'reason' => 'test',
            'is_active' => true,
        ]);

        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Husband Gift', 'slug' => 'husband-gift-normalize'],
            ['relationships' => $husband],
        );

        Livewire::withQueryParams([
            'occasion' => 'bridal-shower',
            'relationship' => 'husband',
        ])->test(GiftListing::class, [
            'context' => DiscoveryListingContext::forGiftIdeas()->toArray(),
        ])
            ->assertSet('relationship', 'husband')
            ->assertSet('occasion', '');
    }

    public function test_selected_zero_count_filter_stays_visible(): void
    {
        $husband = $this->relationship('Husband');
        $this->occasion('Birthday');
        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Unfiltered Gift', 'slug' => 'unfiltered-zero'],
            ['relationships' => $husband],
        );

        $this->get(DiscoveryUrl::relationship('husband').'?occasion=birthday')
            ->assertOk()
            ->assertSee('Birthday', false)
            ->assertSee('(0)', false)
            ->assertSee('No gift ideas match all those filters.', false);
    }

    public function test_seo_landing_page_keeps_fixed_context_out_of_filters(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $tech = Interest::query()->create(['name' => 'Tech & Gadgets', 'slug' => 'technology', 'is_active' => true]);

        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'LP Tech', 'slug' => 'lp-tech'],
            ['relationships' => $husband, 'occasions' => $birthday, 'interests' => $tech],
        );

        $page = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-husband-facets',
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
            'is_indexable' => true,
        ]);

        $html = $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertOk()
            ->assertSee('Tech &amp; Gadgets', false)
            ->assertSee('(1)', false)
            ->getContent();

        $this->assertStringNotContainsString('id="desktop-occasion-panel"', $html);
        $this->assertStringNotContainsString('id="desktop-relationship-panel"', $html);
    }

    public function test_long_product_name_still_renders_on_the_listing(): void
    {
        $husband = $this->relationship('Husband');
        $name = 'Hand-engraved walnut anniversary keepsake box with a surprisingly long editorial title for layout stress';
        $gift = GiftCatalogTestHelpers::publishedGift([
            'name' => $name,
            'slug' => 'long-name-gift',
        ]);
        $gift->relationships()->attach($husband);

        $this->get(DiscoveryUrl::relationship('husband'))
            ->assertOk()
            ->assertSee($name, false)
            ->assertSee('View gift', false);
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

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();

        return count(DB::getQueryLog());
    }
}
