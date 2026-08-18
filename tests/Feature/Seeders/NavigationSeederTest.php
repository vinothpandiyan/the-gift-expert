<?php

namespace Tests\Feature\Seeders;

use App\Actions\Navigation\BuildPrimaryNavigationTreeAction;
use App\Actions\SeoLandingPage\PublishSeoLandingPageAction;
use App\Enums\NavigationItemType;
use App\Enums\NavigationLinkType;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\NavigationLink;
use App\Models\NavigationMenu;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\GiftTypeSeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\OccasionSeeder;
use Database\Seeders\ProfessionSeeder;
use Database\Seeders\RecipientTypeSeeder;
use Database\Seeders\RelationshipSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<class-string>
     */
    private array $prerequisiteSeeders = [
        OccasionSeeder::class,
        RelationshipSeeder::class,
        RecipientTypeSeeder::class,
        InterestSeeder::class,
        ProfessionSeeder::class,
        GiftTypeSeeder::class,
    ];

    public function test_it_creates_expected_top_level_menus(): void
    {
        $this->seedPrerequisitesAndNavigation();

        $this->assertSame(
            ['by-recipient', 'by-occasion', 'by-interest', 'by-profession', 'digital-gifts', 'return-gifts'],
            NavigationMenu::query()->orderBy('sort_order')->pluck('slug')->all(),
        );
        $this->assertSame(0, NavigationMenu::query()->where('slug', 'blog')->count());
        $this->assertTrue(NavigationMenu::query()->where('slug', 'by-recipient')->first()?->item_type === NavigationItemType::Mega);
    }

    public function test_it_is_idempotent(): void
    {
        $this->seedPrerequisitesAndNavigation();

        $counts = [
            NavigationMenu::query()->count(),
            $this->sectionCount(),
            NavigationLink::query()->count(),
        ];

        $this->seed(NavigationSeeder::class);

        $this->assertSame($counts, [
            NavigationMenu::query()->count(),
            $this->sectionCount(),
            NavigationLink::query()->count(),
        ]);
        $this->assertSame(1, NavigationMenu::query()->where('slug', 'by-recipient')->count());
    }

    public function test_by_recipient_uses_existing_relationships_and_special_targets(): void
    {
        $this->seedPrerequisitesAndNavigation();

        $husband = Relationship::query()->where('slug', 'husband')->firstOrFail();
        $kids = RecipientType::query()->where('slug', 'kids')->firstOrFail();
        $eco = Interest::query()->where('slug', 'eco-friendly')->firstOrFail();

        $husbandLink = $this->linkOnMenu('by-recipient', 'Gifts for Husband');
        $this->assertSame(NavigationLinkType::Relationship, $husbandLink->link_type);
        $this->assertSame($husband->id, $husbandLink->linkable_id);
        $this->assertNull($husbandLink->url);
        $this->assertNull($husbandLink->route_key);

        $kidsLink = $this->linkOnMenu('by-recipient', 'Gifts for Kids');
        $this->assertSame(NavigationLinkType::RecipientType, $kidsLink->link_type);
        $this->assertSame($kids->id, $kidsLink->linkable_id);
        $this->assertNotSame(
            Relationship::query()->where('slug', 'kids')->value('id'),
            $kidsLink->linkable_id,
        );

        $ecoLink = $this->linkOnMenu('by-recipient', 'Eco-friendly gifts');
        $this->assertSame(NavigationLinkType::Interest, $ecoLink->link_type);
        $this->assertSame($eco->id, $ecoLink->linkable_id);
    }

    public function test_browse_all_uses_gift_ideas_discovery_route(): void
    {
        $this->seedPrerequisitesAndNavigation();

        $browse = NavigationLink::query()
            ->where('link_type', NavigationLinkType::DiscoveryRoute)
            ->where('route_key', 'gift_ideas.index')
            ->get();

        $this->assertGreaterThanOrEqual(5, $browse->count());
        $this->assertTrue($browse->every(fn (NavigationLink $link): bool => $link->url === null && $link->linkable_id === null));
        $this->assertNotNull($this->linkOnMenu('by-recipient', 'View all recipients'));
    }

    public function test_seeded_links_only_reference_existing_taxonomy_and_gift_types(): void
    {
        $this->seedPrerequisitesAndNavigation();

        $this->assertLinkSlugsMatch('by-occasion', NavigationLinkType::Occasion, Occasion::class, [
            'birthday', 'anniversary', 'wedding', 'engagement',
            'baby-shower', 'housewarming', 'farewell', 'retirement',
            'diwali', 'pongal', 'raksha-bandhan', 'eid', 'christmas', 'new-year', 'festival',
        ]);
        $this->assertLinkSlugsMatch('by-interest', NavigationLinkType::Interest, Interest::class, [
            'food', 'coffee', 'fitness', 'travel', 'pets', 'books', 'music', 'photography', 'technology', 'eco-friendly',
        ]);
        $this->assertLinkSlugsMatch('by-profession', NavigationLinkType::Profession, Profession::class, [
            'doctor', 'engineer', 'software-developer', 'designer', 'business-owner', 'ca-finance', 'content-creator', 'teacher',
        ]);
        $this->assertLinkSlugsMatch('digital-gifts', NavigationLinkType::GiftType, GiftType::class, [
            'gift-cards', 'subscriptions', 'digital-instant-gifts', 'online-courses', 'ebooks-audiobooks',
        ]);

        $returnGifts = GiftType::query()->where('slug', 'return-gifts')->firstOrFail();
        $returnLink = $this->linkOnMenu('return-gifts', 'View all return gifts');
        $this->assertSame(NavigationLinkType::GiftType, $returnLink->link_type);
        $this->assertSame($returnGifts->id, $returnLink->linkable_id);
    }

    public function test_return_gifts_menu_has_intended_editorial_sections(): void
    {
        $this->seedPrerequisitesAndNavigation();

        $menu = NavigationMenu::query()->where('slug', 'return-gifts')->firstOrFail();
        $headings = $menu->sections()->where('is_active', true)->orderBy('sort_order')->pluck('heading')->all();

        $this->assertSame(['BY EVENT', 'CORPORATE', 'BY BUDGET', 'BROWSE ALL'], $headings);
        $this->assertSame(0, $this->sectionLinkCount($menu, 'CORPORATE'));
        $this->assertSame(0, $this->sectionLinkCount($menu, 'BY EVENT'));
        $this->assertSame(0, $this->sectionLinkCount($menu, 'BY BUDGET'));
    }

    public function test_return_gift_seo_landing_links_are_editorial_until_discoverable(): void
    {
        $this->seed(DatabaseSeeder::class);

        $menu = NavigationMenu::query()->where('slug', 'return-gifts')->firstOrFail();
        $this->assertSame(
            ['BY EVENT', 'CORPORATE', 'BY BUDGET', 'BROWSE ALL'],
            $menu->sections()->where('is_active', true)->orderBy('sort_order')->pluck('heading')->all(),
        );
        $this->assertSame(0, $this->sectionLinkCount($menu, 'CORPORATE'));
        $this->assertSame(4, $this->sectionLinkCount($menu, 'BY EVENT'));
        $this->assertSame(1, $this->sectionLinkCount($menu, 'BY BUDGET'));

        $lpLinks = NavigationLink::query()
            ->whereIn('navigation_section_id', $menu->sections()->pluck('id'))
            ->where('link_type', NavigationLinkType::SeoLandingPage)
            ->get();

        $this->assertTrue($lpLinks->every(fn (NavigationLink $link): bool => $link->url === null && $link->route_key === null));
        $this->assertEqualsCanonicalizing(
            [
                'birthday-return-gifts',
                'wedding-return-gifts',
                'baby-shower-return-gifts',
                'engagement-return-gifts',
                'return-gifts-under-500',
            ],
            SeoLandingPage::query()->whereIn('id', $lpLinks->pluck('linkable_id'))->pluck('slug')->all(),
        );

        $tree = app(BuildPrimaryNavigationTreeAction::class)->execute();
        $returnMenu = collect($tree)->firstWhere('slug', 'return-gifts');

        $this->assertIsArray($returnMenu);
        $this->assertSame(['BROWSE ALL'], collect($returnMenu['sections'])->pluck('heading')->all());
        $this->assertSame(DiscoveryUrl::giftType('return-gifts'), $this->treeHref($returnMenu, 'View all return gifts'));
        $this->assertStringNotContainsString('/birthday-return-gifts', json_encode($returnMenu));
    }

    public function test_published_return_gift_landing_page_resolves_through_navigation(): void
    {
        $this->seed(DatabaseSeeder::class);

        $page = SeoLandingPage::query()->where('slug', 'birthday-return-gifts')->firstOrFail();
        $page->is_indexable = true;
        $page->save();
        app(PublishSeoLandingPageAction::class)->execute($page->fresh());

        $tree = app(BuildPrimaryNavigationTreeAction::class)->execute();
        $returnMenu = collect($tree)->firstWhere('slug', 'return-gifts');

        $this->assertIsArray($returnMenu);
        $this->assertContains('BY EVENT', collect($returnMenu['sections'])->pluck('heading')->all());
        $this->assertSame(
            DiscoveryUrl::seoLandingPage('birthday-return-gifts'),
            $this->treeHref($returnMenu, 'Birthday return gifts'),
        );
    }

    public function test_it_does_not_store_urls_or_create_seo_landing_pages(): void
    {
        $this->seed($this->prerequisiteSeeders);
        SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-husband',
            'is_indexable' => true,
        ]);
        $relationshipCount = Relationship::query()->count();

        $this->seed(NavigationSeeder::class);

        $this->assertSame(0, NavigationLink::query()->whereNotNull('url')->count());
        $this->assertSame(0, NavigationLink::query()->where('url', 'like', '/%')->count());
        $this->assertSame($relationshipCount, Relationship::query()->count());
        $this->assertSame(1, SeoLandingPage::query()->count());
        $this->assertSame(0, NavigationMenu::query()->where('slug', 'blog')->count());
        $this->assertSame(0, NavigationLink::query()->where('link_type', NavigationLinkType::SeoLandingPage)->count());
    }

    public function test_it_does_not_delete_manually_created_navigation_records(): void
    {
        $this->seedPrerequisitesAndNavigation();

        $manual = NavigationMenu::factory()->link()->create([
            'label' => 'Editorial Extra',
            'slug' => 'editorial-extra',
            'link_type' => NavigationLinkType::ExternalUrl,
            'url' => 'https://example.com/manual',
            'is_active' => true,
        ]);

        $this->seed(NavigationSeeder::class);

        $this->assertTrue(NavigationMenu::query()->whereKey($manual->id)->exists());
        $this->assertSame('https://example.com/manual', $manual->fresh()->url);
        $this->assertSame(1, NavigationMenu::query()->where('slug', 'editorial-extra')->count());
    }

    public function test_resolved_tree_uses_discovery_urls_for_seeded_targets(): void
    {
        $this->seedPrerequisitesAndNavigation();

        $tree = app(BuildPrimaryNavigationTreeAction::class)->execute();
        $bySlug = collect($tree)->keyBy('slug');

        $this->assertSame(DiscoveryUrl::relationship('husband'), $this->treeHref($bySlug['by-recipient'], 'Gifts for Husband'));
        $this->assertSame(DiscoveryUrl::recipientType('kids'), $this->treeHref($bySlug['by-recipient'], 'Gifts for Kids'));
        $this->assertSame(DiscoveryUrl::interest('eco-friendly'), $this->treeHref($bySlug['by-recipient'], 'Eco-friendly gifts'));
        $this->assertSame(DiscoveryUrl::giftIdeas(), $this->treeHref($bySlug['by-recipient'], 'View all recipients'));
        $this->assertSame(DiscoveryUrl::occasion('birthday'), $this->treeHref($bySlug['by-occasion'], 'Birthday Gifts'));
        $this->assertSame(DiscoveryUrl::interest('coffee'), $this->treeHref($bySlug['by-interest'], 'Gifts for Coffee Lovers'));
        $this->assertSame(DiscoveryUrl::profession('doctor'), $this->treeHref($bySlug['by-profession'], 'Gifts for Doctor'));
        $this->assertSame(DiscoveryUrl::giftType('gift-cards'), $this->treeHref($bySlug['digital-gifts'], 'Gift Cards'));
        $this->assertSame(DiscoveryUrl::giftType('return-gifts'), $this->treeHref($bySlug['return-gifts'], 'View all return gifts'));
        $this->assertFalse(collect($tree)->pluck('slug')->contains('blog'));
        $this->assertSame(['BROWSE ALL'], collect($bySlug['return-gifts']['sections'])->pluck('heading')->all());
        $this->assertTrue(collect($tree)->every(function (array $menu): bool {
            if (($menu['item_type'] ?? '') === 'link') {
                return filled($menu['href'] ?? null);
            }

            foreach ($menu['sections'] ?? [] as $section) {
                foreach ($section['links'] as $link) {
                    if (! filled($link['href'] ?? null)) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    public function test_omitted_entities_are_not_invented_as_links(): void
    {
        $this->seedPrerequisitesAndNavigation();

        $labels = NavigationLink::query()->pluck('label');

        $this->assertFalse($labels->contains('Gifts for Nurse'));
        $this->assertFalse($labels->contains('Gifts for Gamers'));
        $this->assertFalse($labels->contains("Valentine's Day Gifts"));
        $this->assertFalse($labels->contains('Return gift calculator'));
    }

    private function seedPrerequisitesAndNavigation(): void
    {
        $this->seed($this->prerequisiteSeeders);
        $this->seed(NavigationSeeder::class);
    }

    private function sectionCount(): int
    {
        return NavigationMenu::query()
            ->withCount('sections')
            ->get()
            ->sum('sections_count');
    }

    private function sectionLinkCount(NavigationMenu $menu, string $heading): int
    {
        $section = $menu->sections()->where('heading', $heading)->where('is_active', true)->firstOrFail();

        return $section->links()->where('is_active', true)->count();
    }

    private function linkOnMenu(string $menuSlug, string $label): NavigationLink
    {
        $menu = NavigationMenu::query()->where('slug', $menuSlug)->firstOrFail();

        return NavigationLink::query()
            ->whereIn('navigation_section_id', $menu->sections()->pluck('id'))
            ->where('label', $label)
            ->firstOrFail();
    }

    /**
     * @param  class-string  $modelClass
     * @param  list<string>  $expectedSlugs
     */
    private function assertLinkSlugsMatch(
        string $menuSlug,
        NavigationLinkType $linkType,
        string $modelClass,
        array $expectedSlugs,
    ): void {
        $menu = NavigationMenu::query()->where('slug', $menuSlug)->firstOrFail();
        $ids = NavigationLink::query()
            ->whereIn('navigation_section_id', $menu->sections()->pluck('id'))
            ->where('link_type', $linkType)
            ->pluck('linkable_id');

        $slugs = $modelClass::query()->whereIn('id', $ids)->pluck('slug')->sort()->values();
        $this->assertSame(collect($expectedSlugs)->sort()->values()->all(), $slugs->all());
        $this->assertTrue($modelClass::query()->whereIn('slug', $expectedSlugs)->where('is_active', true)->exists());
    }

    /**
     * @param  array<string, mixed>  $menu
     */
    private function treeHref(array $menu, string $label): string
    {
        foreach ($menu['sections'] as $section) {
            foreach ($section['links'] as $link) {
                if ($link['label'] === $label) {
                    return $link['href'];
                }
            }
        }

        $this->fail("Missing tree link [{$label}] on [{$menu['slug']}].");
    }
}
