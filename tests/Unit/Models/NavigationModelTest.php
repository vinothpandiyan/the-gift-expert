<?php

namespace Tests\Unit\Models;

use App\Enums\NavigationItemType;
use App\Enums\NavigationLinkType;
use App\Enums\NavigationSectionAppearance;
use App\Models\NavigationLink;
use App\Models\NavigationMenu;
use App\Models\NavigationSection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_menu_can_have_many_sections(): void
    {
        $menu = NavigationMenu::factory()->create();

        $first = NavigationSection::factory()->create([
            'navigation_menu_id' => $menu->id,
            'heading' => 'For Him',
        ]);
        $second = NavigationSection::factory()->create([
            'navigation_menu_id' => $menu->id,
            'heading' => 'For Her',
        ]);

        $this->assertCount(2, $menu->sections);
        $this->assertTrue($menu->sections->contains($first));
        $this->assertTrue($menu->sections->contains($second));
    }

    public function test_navigation_section_belongs_to_navigation_menu(): void
    {
        $menu = NavigationMenu::factory()->create();
        $section = NavigationSection::factory()->create([
            'navigation_menu_id' => $menu->id,
        ]);

        $this->assertTrue($section->menu->is($menu));
        $this->assertSame('navigation_menus', $section->menu()->getRelated()->getTable());
    }

    public function test_navigation_section_can_have_many_links(): void
    {
        $section = NavigationSection::factory()->create();

        $first = NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Gifts for husband',
        ]);
        $second = NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Gifts for father',
        ]);

        $this->assertCount(2, $section->links);
        $this->assertTrue($section->links->contains($first));
        $this->assertTrue($section->links->contains($second));
    }

    public function test_navigation_link_belongs_to_navigation_section(): void
    {
        $section = NavigationSection::factory()->create();
        $link = NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
        ]);

        $this->assertTrue($link->section->is($section));
        $this->assertSame('navigation_sections', $link->section()->getRelated()->getTable());
    }

    public function test_section_relationship_is_ordered_by_sort_order(): void
    {
        $menu = NavigationMenu::factory()->create();

        $later = NavigationSection::factory()->create([
            'navigation_menu_id' => $menu->id,
            'heading' => 'Later',
            'sort_order' => 20,
        ]);
        $earlier = NavigationSection::factory()->create([
            'navigation_menu_id' => $menu->id,
            'heading' => 'Earlier',
            'sort_order' => 5,
        ]);

        $this->assertTrue($menu->sections->first()->is($earlier));
        $this->assertTrue($menu->sections->last()->is($later));
        $this->assertSame(['Earlier', 'Later'], $menu->sections->pluck('heading')->all());
    }

    public function test_link_relationship_is_ordered_by_sort_order(): void
    {
        $section = NavigationSection::factory()->create();

        $later = NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Later',
            'sort_order' => 20,
        ]);
        $earlier = NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Earlier',
            'sort_order' => 5,
        ]);

        $this->assertTrue($section->links->first()->is($earlier));
        $this->assertTrue($section->links->last()->is($later));
        $this->assertSame(['Earlier', 'Later'], $section->links->pluck('label')->all());
    }

    public function test_deleting_a_navigation_menu_cascades_to_sections_and_links(): void
    {
        $menu = NavigationMenu::factory()->create();
        $section = NavigationSection::factory()->create([
            'navigation_menu_id' => $menu->id,
        ]);
        $link = NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
        ]);

        $menu->delete();

        $this->assertDatabaseMissing('navigation_menus', ['id' => $menu->id]);
        $this->assertDatabaseMissing('navigation_sections', ['id' => $section->id]);
        $this->assertDatabaseMissing('navigation_links', ['id' => $link->id]);
    }

    public function test_deleting_a_navigation_section_cascades_to_links(): void
    {
        $section = NavigationSection::factory()->create();
        $link = NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
        ]);

        $section->delete();

        $this->assertDatabaseHas('navigation_menus', ['id' => $section->navigation_menu_id]);
        $this->assertDatabaseMissing('navigation_sections', ['id' => $section->id]);
        $this->assertDatabaseMissing('navigation_links', ['id' => $link->id]);
    }

    public function test_navigation_menu_slug_is_unique(): void
    {
        NavigationMenu::factory()->create(['slug' => 'by-recipient']);

        $this->expectException(QueryException::class);

        NavigationMenu::factory()->create(['slug' => 'by-recipient']);
    }

    public function test_enum_casts_work_on_models(): void
    {
        $menu = NavigationMenu::factory()->link()->create([
            'link_type' => NavigationLinkType::DiscoveryRoute,
            'route_key' => 'gift_ideas.index',
        ]);
        $section = NavigationSection::factory()->cta()->create([
            'navigation_menu_id' => $menu->id,
        ]);
        $link = NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'link_type' => NavigationLinkType::SeoLandingPage,
        ]);

        $menu->refresh();
        $section->refresh();
        $link->refresh();

        $this->assertSame(NavigationItemType::Link, $menu->item_type);
        $this->assertSame(NavigationLinkType::DiscoveryRoute, $menu->link_type);
        $this->assertSame(NavigationSectionAppearance::Cta, $section->appearance);
        $this->assertSame(NavigationLinkType::SeoLandingPage, $link->link_type);
    }

    public function test_database_defaults_for_active_sort_order_and_opens_in_new_tab(): void
    {
        $menu = NavigationMenu::query()->create([
            'label' => 'By Recipient',
            'slug' => 'by-recipient',
        ]);
        $section = NavigationSection::query()->create([
            'navigation_menu_id' => $menu->id,
        ]);
        $link = NavigationLink::query()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Gifts for husband',
            'link_type' => NavigationLinkType::Relationship,
        ]);

        $menu->refresh();
        $section->refresh();
        $link->refresh();

        $this->assertSame(NavigationItemType::Mega, $menu->item_type);
        $this->assertSame(0, $menu->sort_order);
        $this->assertTrue($menu->is_active);
        $this->assertFalse($menu->opens_in_new_tab);

        $this->assertSame(NavigationSectionAppearance::Default, $section->appearance);
        $this->assertSame(0, $section->sort_order);
        $this->assertTrue($section->is_active);

        $this->assertSame(0, $link->sort_order);
        $this->assertTrue($link->is_active);
        $this->assertFalse($link->opens_in_new_tab);
    }
}
