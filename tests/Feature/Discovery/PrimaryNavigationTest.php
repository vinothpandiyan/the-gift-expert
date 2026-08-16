<?php

namespace Tests\Feature\Discovery;

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
use App\Support\Terminology;
use Database\Seeders\GiftTypeSeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\OccasionSeeder;
use Database\Seeders\ProfessionSeeder;
use Database\Seeders\RecipientTypeSeeder;
use Database\Seeders\RelationshipSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrimaryNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_mega_menu_renders_resolved_urls_accessibility_hooks_and_minimal_footer(): void
    {
        $this->seed([
            OccasionSeeder::class,
            RelationshipSeeder::class,
            RecipientTypeSeeder::class,
            InterestSeeder::class,
            ProfessionSeeder::class,
            GiftTypeSeeder::class,
            NavigationSeeder::class,
        ]);

        $html = $this->get(DiscoveryUrl::giftIdeas())->assertOk()->getContent();
        $header = $this->htmlFragment($html, 'header');
        $footer = $this->htmlFragment($html, 'footer');
        $husbandHref = DiscoveryUrl::relationship('husband');
        $kidsHref = DiscoveryUrl::recipientType('kids');
        $doctorHref = DiscoveryUrl::profession('doctor');
        $giftCardsHref = DiscoveryUrl::giftType('gift-cards');

        $this->assertStringContainsString('By Recipient', $header);
        $this->assertStringContainsString('By Occasion', $header);
        $this->assertStringContainsString('By Interest', $header);
        $this->assertStringContainsString('By Profession', $header);
        $this->assertStringContainsString('Digital Gifts', $header);
        $this->assertStringContainsString('Return Gifts', $header);
        $this->assertStringContainsString('Gifts for Husband', $header);
        $this->assertStringContainsString('Gifts for Kids', $header);
        $this->assertStringContainsString('href="'.$husbandHref.'"', $header);
        $this->assertStringContainsString('href="'.$kidsHref.'"', $header);
        $this->assertStringContainsString('href="'.$doctorHref.'"', $header);
        $this->assertStringContainsString('href="'.$giftCardsHref.'"', $header);
        $this->assertGreaterThanOrEqual(2, substr_count($header, 'href="'.$husbandHref.'"'));
        $this->assertGreaterThanOrEqual(2, substr_count($header, 'href="'.$kidsHref.'"'));

        $this->assertMatchesRegularExpression(
            '/<button[^>]*aria-controls="mega-menu-by-recipient"[^>]*>/i',
            $header,
        );
        $this->assertMatchesRegularExpression(
            '/<button[^>]*aria-haspopup="true"[^>]*aria-controls="mega-menu-by-recipient"|<button[^>]*aria-controls="mega-menu-by-recipient"[^>]*aria-haspopup="true"/i',
            $header,
        );
        $this->assertStringContainsString('aria-expanded="false"', $header);
        $this->assertStringContainsString('id="mega-menu-by-recipient"', $header);
        $this->assertSame(1, substr_count($html, 'id="mega-menu-by-recipient"'));
        $this->assertStringContainsString('aria-label="Open menu"', $header);
        $this->assertStringContainsString('id="mobile-primary-nav"', $header);
        $this->assertStringContainsString('Find a Gift', $header);
        $this->assertStringContainsString('href="'.DiscoveryUrl::finder().'"', $header);

        $this->assertStringContainsString('Find a Gift', $footer);
        $this->assertStringContainsString(Terminology::giftIdeas(), $footer);
        $this->assertStringContainsString('href="'.DiscoveryUrl::finder().'"', $footer);
        $this->assertStringContainsString('href="'.DiscoveryUrl::giftIdeas().'"', $footer);
        $this->assertStringNotContainsString('By Recipient', $footer);
        $this->assertStringNotContainsString('Gifts for Husband', $footer);
        $this->assertStringNotContainsString('FOR HIM', $footer);

        $this->assertDoesNotMatchRegularExpression('/href=["\']\s*["\']/', $html);
    }

    public function test_discoverable_seo_landing_page_renders_and_draft_or_noindex_does_not(): void
    {
        $menu = NavigationMenu::factory()->mega()->create([
            'label' => 'Ideas',
            'slug' => 'ideas',
        ]);
        $section = NavigationSection::factory()->create([
            'navigation_menu_id' => $menu->id,
            'heading' => 'Featured',
        ]);

        $discoverable = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-husband',
            'heading' => 'Birthday Gifts for Husband',
            'is_indexable' => true,
        ]);
        $noindex = SeoLandingPage::factory()->published()->create([
            'slug' => 'hidden-noindex-nav',
            'heading' => 'Hidden Noindex Nav',
            'is_indexable' => false,
        ]);
        $draft = SeoLandingPage::factory()->draft()->create([
            'slug' => 'draft-nav-landing',
            'heading' => 'Draft Nav Landing',
        ]);

        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Birthday Gifts for Husband',
            'link_type' => NavigationLinkType::SeoLandingPage,
            'linkable_id' => $discoverable->id,
            'sort_order' => 1,
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Hidden Noindex Nav',
            'link_type' => NavigationLinkType::SeoLandingPage,
            'linkable_id' => $noindex->id,
            'sort_order' => 2,
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Draft Nav Landing',
            'link_type' => NavigationLinkType::SeoLandingPage,
            'linkable_id' => $draft->id,
            'sort_order' => 3,
        ]);

        $html = $this->get(DiscoveryUrl::giftIdeas())->assertOk()->getContent();

        $this->assertStringContainsString('href="'.DiscoveryUrl::seoLandingPage('birthday-gifts-for-husband').'"', $html);
        $this->assertStringContainsString('Birthday Gifts for Husband', $html);
        $this->assertStringNotContainsString('href="'.DiscoveryUrl::seoLandingPage('hidden-noindex-nav').'"', $html);
        $this->assertStringNotContainsString('Hidden Noindex Nav', $html);
        $this->assertStringNotContainsString('href="'.DiscoveryUrl::seoLandingPage('draft-nav-landing').'"', $html);
        $this->assertStringNotContainsString('Draft Nav Landing', $html);
    }

    public function test_inactive_and_invalid_navigation_is_omitted(): void
    {
        $husband = $this->relationship('Husband', 'husband');
        $wife = $this->relationship('Wife', 'wife');

        $activeMenu = NavigationMenu::factory()->mega()->create([
            'label' => 'By Recipient',
            'slug' => 'by-recipient',
            'sort_order' => 1,
        ]);
        $inactiveMenu = NavigationMenu::factory()->mega()->create([
            'label' => 'Hidden Mega Menu',
            'slug' => 'hidden-mega-menu',
            'is_active' => false,
            'sort_order' => 0,
        ]);
        $inactiveMenuSection = NavigationSection::factory()->create([
            'navigation_menu_id' => $inactiveMenu->id,
            'heading' => 'Hidden Menu Section',
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $inactiveMenuSection->id,
            'label' => 'Hidden Menu Husband',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $husband->id,
        ]);

        $activeSection = NavigationSection::factory()->create([
            'navigation_menu_id' => $activeMenu->id,
            'heading' => 'For Him',
            'sort_order' => 1,
        ]);
        NavigationSection::factory()->create([
            'navigation_menu_id' => $activeMenu->id,
            'heading' => 'Hidden Section',
            'is_active' => false,
            'sort_order' => 0,
        ]);

        NavigationLink::factory()->create([
            'navigation_section_id' => $activeSection->id,
            'label' => 'Gifts for Husband',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $husband->id,
            'sort_order' => 1,
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $activeSection->id,
            'label' => 'Missing Target',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => 99999,
            'sort_order' => 2,
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $activeSection->id,
            'label' => 'Inactive Wife',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $wife->id,
            'is_active' => false,
            'sort_order' => 3,
        ]);

        $hiddenSection = NavigationSection::query()->where('heading', 'Hidden Section')->firstOrFail();
        NavigationLink::factory()->create([
            'navigation_section_id' => $hiddenSection->id,
            'label' => 'Hidden Section Husband',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $husband->id,
        ]);

        $html = $this->get(DiscoveryUrl::giftIdeas())->assertOk()->getContent();

        $this->assertStringContainsString('By Recipient', $html);
        $this->assertStringContainsString('Gifts for Husband', $html);
        $this->assertStringNotContainsString('Hidden Mega Menu', $html);
        $this->assertStringNotContainsString('Hidden Menu Husband', $html);
        $this->assertStringNotContainsString('Hidden Section', $html);
        $this->assertStringNotContainsString('Hidden Section Husband', $html);
        $this->assertStringNotContainsString('Missing Target', $html);
        $this->assertStringNotContainsString('Inactive Wife', $html);
    }

    public function test_category_and_external_links_render_expected_attributes(): void
    {
        $category = Category::query()->create([
            'name' => 'Personalized Gifts',
            'slug' => 'personalized-gifts',
            'is_active' => true,
        ]);

        $menu = NavigationMenu::factory()->mega()->create([
            'label' => 'Browse',
            'slug' => 'browse',
        ]);
        $section = NavigationSection::factory()->cta()->create([
            'navigation_menu_id' => $menu->id,
            'heading' => 'BROWSE ALL',
            'appearance' => NavigationSectionAppearance::Cta,
        ]);

        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Personalized Gifts',
            'link_type' => NavigationLinkType::Category,
            'linkable_id' => $category->id,
            'sort_order' => 1,
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Partner Store',
            'link_type' => NavigationLinkType::ExternalUrl,
            'url' => 'https://example.com/gifts',
            'opens_in_new_tab' => true,
            'sort_order' => 2,
        ]);

        $html = $this->get(DiscoveryUrl::giftIdeas())->assertOk()->getContent();
        $categoryHref = DiscoveryUrl::giftIdeasCategory($category->fresh()->full_path);

        $this->assertStringContainsString('href="'.$categoryHref.'"', $html);
        $this->assertStringContainsString('href="https://example.com/gifts"', $html);
        $this->assertMatchesRegularExpression(
            '/href="https:\/\/example\.com\/gifts"[^>]*target="_blank"[^>]*rel="noopener noreferrer"|href="https:\/\/example\.com\/gifts"[^>]*rel="noopener noreferrer"[^>]*target="_blank"/',
            $html,
        );
    }

    public function test_top_level_link_menus_render_as_anchors_not_mega_buttons(): void
    {
        NavigationMenu::factory()->link()->create([
            'label' => 'Gift Ideas Link',
            'slug' => 'gift-ideas-link',
            'item_type' => NavigationItemType::Link,
            'link_type' => NavigationLinkType::DiscoveryRoute,
            'route_key' => 'gift_ideas.index',
        ]);

        $html = $this->get(DiscoveryUrl::finder())->assertOk()->getContent();
        $header = $this->htmlFragment($html, 'header');

        $this->assertStringContainsString('Gift Ideas Link', $header);
        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*>\s*Gift Ideas Link\s*<\/button>/s',
            $header,
        );
        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="'.preg_quote(DiscoveryUrl::giftIdeas(), '/').'"[^>]*>\s*Gift Ideas Link\s*<\/a>/s',
            $header,
        );
    }

    private function relationship(string $name, string $slug): Relationship
    {
        return Relationship::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    private function htmlFragment(string $html, string $tag): string
    {
        if (preg_match('/<'.$tag.'\b[^>]*>.*<\/'.$tag.'>/is', $html, $matches) !== 1) {
            $this->fail("Missing <{$tag}> in the response.");
        }

        return $matches[0];
    }
}
