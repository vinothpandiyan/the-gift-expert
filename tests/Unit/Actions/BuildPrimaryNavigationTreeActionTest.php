<?php

namespace Tests\Unit\Actions;

use App\Actions\Navigation\BuildPrimaryNavigationTreeAction;
use App\Enums\NavigationItemType;
use App\Enums\NavigationLinkType;
use App\Enums\NavigationSectionAppearance;
use App\Models\Category;
use App\Models\NavigationLink;
use App\Models\NavigationMenu;
use App\Models\NavigationSection;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BuildPrimaryNavigationTreeActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_includes_active_mega_menu_structure_and_omits_inactive_rows(): void
    {
        $husband = $this->relationship('Husband', 'husband');
        $wife = $this->relationship('Wife', 'wife');

        $activeMenu = NavigationMenu::factory()->mega()->create([
            'label' => 'By Recipient',
            'slug' => 'by-recipient',
            'sort_order' => 1,
        ]);
        NavigationMenu::factory()->mega()->create([
            'label' => 'Hidden',
            'slug' => 'hidden',
            'is_active' => false,
            'sort_order' => 0,
        ]);

        $activeSection = NavigationSection::factory()->create([
            'navigation_menu_id' => $activeMenu->id,
            'heading' => 'For Him',
            'sort_order' => 1,
        ]);
        NavigationSection::factory()->create([
            'navigation_menu_id' => $activeMenu->id,
            'heading' => 'Inactive section',
            'is_active' => false,
            'sort_order' => 0,
        ]);

        NavigationLink::factory()->create([
            'navigation_section_id' => $activeSection->id,
            'label' => 'Gifts for husband',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $husband->id,
            'sort_order' => 1,
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $activeSection->id,
            'label' => 'Inactive wife',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $wife->id,
            'is_active' => false,
            'sort_order' => 0,
        ]);

        $tree = app(BuildPrimaryNavigationTreeAction::class)->execute();

        $this->assertCount(1, $tree);
        $this->assertSame('By Recipient', $tree[0]['label']);
        $this->assertSame('mega', $tree[0]['item_type']);
        $this->assertCount(1, $tree[0]['sections']);
        $this->assertSame('For Him', $tree[0]['sections'][0]['heading']);
        $this->assertSame('default', $tree[0]['sections'][0]['appearance']);
        $this->assertCount(1, $tree[0]['sections'][0]['links']);
        $this->assertSame('Gifts for husband', $tree[0]['sections'][0]['links'][0]['label']);
        $this->assertSame(DiscoveryUrl::relationship('husband'), $tree[0]['sections'][0]['links'][0]['href']);
        $this->assertFalse($tree[0]['sections'][0]['links'][0]['opens_in_new_tab']);
        $this->assertSame(json_decode(json_encode($tree), true), $tree);
    }

    public function test_it_orders_menus_sections_and_links_by_sort_order(): void
    {
        $husband = $this->relationship('Husband', 'husband');
        $father = $this->relationship('Father', 'father');

        $second = NavigationMenu::factory()->mega()->create([
            'label' => 'Second',
            'slug' => 'second',
            'sort_order' => 20,
        ]);
        $first = NavigationMenu::factory()->mega()->create([
            'label' => 'First',
            'slug' => 'first',
            'sort_order' => 5,
        ]);

        $secondSection = NavigationSection::factory()->create([
            'navigation_menu_id' => $second->id,
            'heading' => 'Also',
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $secondSection->id,
            'label' => 'Husband second',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $husband->id,
        ]);

        $laterSection = NavigationSection::factory()->create([
            'navigation_menu_id' => $first->id,
            'heading' => 'Later',
            'sort_order' => 20,
        ]);
        $earlierSection = NavigationSection::factory()->create([
            'navigation_menu_id' => $first->id,
            'heading' => 'Earlier',
            'sort_order' => 5,
        ]);

        NavigationLink::factory()->create([
            'navigation_section_id' => $earlierSection->id,
            'label' => 'Father',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $father->id,
            'sort_order' => 20,
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $earlierSection->id,
            'label' => 'Husband',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $husband->id,
            'sort_order' => 5,
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $laterSection->id,
            'label' => 'Husband later',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $husband->id,
        ]);

        $tree = app(BuildPrimaryNavigationTreeAction::class)->execute();

        $this->assertSame(['First', 'Second'], array_column($tree, 'label'));
        $this->assertSame(['Earlier', 'Later'], array_column($tree[0]['sections'], 'heading'));
        $this->assertSame(['Husband', 'Father'], array_column($tree[0]['sections'][0]['links'], 'label'));
        $this->assertSame($second->slug, $tree[1]['slug']);
    }

    public function test_it_omits_invalid_links_empty_sections_and_empty_mega_menus(): void
    {
        $menu = NavigationMenu::factory()->mega()->create([
            'label' => 'Empty mega',
            'slug' => 'empty-mega',
        ]);
        $section = NavigationSection::factory()->create([
            'navigation_menu_id' => $menu->id,
            'heading' => 'Broken',
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Missing husband',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => 99999,
        ]);

        $this->assertSame([], app(BuildPrimaryNavigationTreeAction::class)->execute());
    }

    public function test_it_resolves_top_level_link_menu_and_omits_unresolvable_ones(): void
    {
        NavigationMenu::factory()->link()->create([
            'label' => 'Gift Ideas',
            'slug' => 'gift-ideas',
            'link_type' => NavigationLinkType::DiscoveryRoute,
            'route_key' => 'gift_ideas.index',
            'sort_order' => 1,
        ]);
        NavigationMenu::factory()->link()->create([
            'label' => 'Broken blog',
            'slug' => 'blog',
            'link_type' => NavigationLinkType::DiscoveryRoute,
            'route_key' => 'missing.route',
            'sort_order' => 2,
        ]);

        $tree = app(BuildPrimaryNavigationTreeAction::class)->execute();

        $this->assertCount(1, $tree);
        $this->assertSame('link', $tree[0]['item_type']);
        $this->assertSame(DiscoveryUrl::giftIdeas(), $tree[0]['href']);
        $this->assertSame('discovery_route', $tree[0]['link_type']);
        $this->assertArrayNotHasKey('sections', $tree[0]);
    }

    public function test_it_resolves_category_seo_landing_page_external_and_discovery_route_links(): void
    {
        $category = Category::query()->create([
            'name' => 'Personalized Gifts',
            'slug' => 'personalized-gifts',
            'is_active' => true,
        ]);
        $page = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-husband',
            'is_indexable' => true,
        ]);
        SeoLandingPage::factory()->published()->create([
            'slug' => 'hidden-noindex',
            'is_indexable' => false,
        ]);

        $menu = NavigationMenu::factory()->mega()->create([
            'label' => 'Mixed',
            'slug' => 'mixed',
        ]);
        $section = NavigationSection::factory()->cta()->create([
            'navigation_menu_id' => $menu->id,
            'heading' => 'Browse',
        ]);

        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Personalized',
            'link_type' => NavigationLinkType::Category,
            'linkable_id' => $category->id,
            'sort_order' => 1,
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Birthday Gifts for Husband',
            'link_type' => NavigationLinkType::SeoLandingPage,
            'linkable_id' => $page->id,
            'sort_order' => 2,
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Noindex',
            'link_type' => NavigationLinkType::SeoLandingPage,
            'linkable_id' => SeoLandingPage::query()->where('slug', 'hidden-noindex')->value('id'),
            'sort_order' => 3,
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Partner',
            'link_type' => NavigationLinkType::ExternalUrl,
            'url' => 'https://example.com/gifts',
            'opens_in_new_tab' => true,
            'sort_order' => 4,
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'All ideas',
            'link_type' => NavigationLinkType::DiscoveryRoute,
            'route_key' => 'gift_ideas.index',
            'sort_order' => 5,
        ]);

        $tree = app(BuildPrimaryNavigationTreeAction::class)->execute();
        $links = $tree[0]['sections'][0]['links'];

        $this->assertSame(NavigationSectionAppearance::Cta->value, $tree[0]['sections'][0]['appearance']);
        $this->assertSame([
            'Personalized',
            'Birthday Gifts for Husband',
            'Partner',
            'All ideas',
        ], array_column($links, 'label'));
        $this->assertSame(DiscoveryUrl::giftIdeasCategory($category->full_path), $links[0]['href']);
        $this->assertSame(DiscoveryUrl::seoLandingPage('birthday-gifts-for-husband'), $links[1]['href']);
        $this->assertSame('https://example.com/gifts', $links[2]['href']);
        $this->assertTrue($links[2]['opens_in_new_tab']);
        $this->assertSame(DiscoveryUrl::giftIdeas(), $links[3]['href']);
    }

    public function test_it_returns_cached_tree_without_querying_on_second_call(): void
    {
        $husband = $this->relationship('Husband', 'husband');
        $menu = NavigationMenu::factory()->mega()->create([
            'label' => 'By Recipient',
            'slug' => 'by-recipient',
        ]);
        $section = NavigationSection::factory()->create([
            'navigation_menu_id' => $menu->id,
            'heading' => 'For Him',
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Gifts for husband',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $husband->id,
        ]);

        $action = app(BuildPrimaryNavigationTreeAction::class);
        $first = $action->execute();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $second = $action->execute();

        $this->assertSame($first, $second);
        $this->assertSame([], DB::getQueryLog());
        $this->assertTrue(Cache::has(BuildPrimaryNavigationTreeAction::CACHE_KEY));
    }

    public function test_it_invalidates_cache_when_linked_seo_landing_page_becomes_noindex(): void
    {
        $page = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-husband',
            'is_indexable' => true,
        ]);
        $menu = NavigationMenu::factory()->mega()->create([
            'label' => 'Ideas',
            'slug' => 'ideas',
        ]);
        $section = NavigationSection::factory()->create([
            'navigation_menu_id' => $menu->id,
            'heading' => 'Featured',
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Birthday Gifts for Husband',
            'link_type' => NavigationLinkType::SeoLandingPage,
            'linkable_id' => $page->id,
        ]);

        $action = app(BuildPrimaryNavigationTreeAction::class);

        $this->assertNotSame([], $action->execute());

        $page->update(['is_indexable' => false]);

        $this->assertSame([], $action->execute());

        $page->update(['is_indexable' => true]);

        $this->assertSame(
            DiscoveryUrl::seoLandingPage('birthday-gifts-for-husband'),
            $action->execute()[0]['sections'][0]['links'][0]['href'],
        );
    }

    public function test_it_invalidates_cache_when_a_navigation_link_is_deactivated(): void
    {
        $husband = $this->relationship('Husband', 'husband');
        $menu = NavigationMenu::factory()->mega()->create([
            'label' => 'By Recipient',
            'slug' => 'by-recipient',
        ]);
        $section = NavigationSection::factory()->create([
            'navigation_menu_id' => $menu->id,
            'heading' => 'For Him',
        ]);
        $link = NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Gifts for husband',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $husband->id,
        ]);

        $action = app(BuildPrimaryNavigationTreeAction::class);

        $this->assertNotSame([], $action->execute());

        $link->update(['is_active' => false]);

        $this->assertSame([], $action->execute());
    }

    public function test_it_invalidates_cache_when_linked_relationship_is_deactivated(): void
    {
        $husband = $this->relationship('Husband', 'husband');
        $menu = NavigationMenu::factory()->mega()->create([
            'label' => 'By Recipient',
            'slug' => 'by-recipient',
        ]);
        $section = NavigationSection::factory()->create([
            'navigation_menu_id' => $menu->id,
            'heading' => 'For Him',
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Gifts for husband',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $husband->id,
        ]);

        $action = app(BuildPrimaryNavigationTreeAction::class);

        $this->assertNotSame([], $action->execute());

        $husband->update(['is_active' => false]);

        $this->assertSame([], $action->execute());
    }

    public function test_it_invalidates_cache_when_navigation_records_change(): void
    {
        NavigationMenu::factory()->link()->create([
            'label' => 'Gift Ideas',
            'slug' => 'gift-ideas',
            'item_type' => NavigationItemType::Link,
            'link_type' => NavigationLinkType::DiscoveryRoute,
            'route_key' => 'gift_ideas.index',
        ]);

        $action = app(BuildPrimaryNavigationTreeAction::class);
        $this->assertSame('Gift Ideas', $action->execute()[0]['label']);

        NavigationMenu::query()->where('slug', 'gift-ideas')->first()->update([
            'label' => 'Browse gifts',
        ]);

        $this->assertSame('Browse gifts', $action->execute()[0]['label']);
    }

    private function relationship(string $name, string $slug): Relationship
    {
        return Relationship::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }
}
