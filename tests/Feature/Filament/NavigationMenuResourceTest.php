<?php

namespace Tests\Feature\Filament;

use App\Actions\Navigation\BuildPrimaryNavigationTreeAction;
use App\Actions\Navigation\ValidateNavigationTargetAction;
use App\Enums\NavigationItemType;
use App\Enums\NavigationLinkType;
use App\Filament\Resources\NavigationMenus\NavigationMenuResource;
use App\Filament\Resources\NavigationMenus\Pages\CreateNavigationMenu;
use App\Filament\Resources\NavigationMenus\Pages\EditNavigationMenu;
use App\Filament\Resources\NavigationMenus\Pages\ListNavigationMenus;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\NavigationLink;
use App\Models\NavigationMenu;
use App\Models\NavigationSection;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Models\User;
use App\Support\DiscoveryUrl;
use Database\Seeders\GiftTypeSeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\OccasionSeeder;
use Database\Seeders\ProfessionSeeder;
use Database\Seeders\RecipientTypeSeeder;
use Database\Seeders\RelationshipSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class NavigationMenuResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_is_in_the_site_group_and_list_page_loads(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertSame('Site', NavigationMenuResource::getNavigationGroup());

        Livewire::test(ListNavigationMenus::class)
            ->assertOk();
    }

    public function test_list_page_renders_seeded_navigation_menus(): void
    {
        $this->actingAs(User::factory()->create());
        $this->seed([
            OccasionSeeder::class,
            RelationshipSeeder::class,
            RecipientTypeSeeder::class,
            InterestSeeder::class,
            ProfessionSeeder::class,
            GiftTypeSeeder::class,
            NavigationSeeder::class,
        ]);

        Livewire::test(ListNavigationMenus::class)
            ->assertOk()
            ->assertSee('By Recipient')
            ->assertSee('By Occasion')
            ->assertSee('Digital Gifts');
    }

    public function test_it_can_create_a_mega_menu_with_sections_and_links(): void
    {
        $this->actingAs(User::factory()->create());
        $husband = $this->relationship();

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm($this->megaForm($husband->id))
            ->call('create')
            ->assertHasNoFormErrors();

        $menu = NavigationMenu::query()->where('slug', 'by-recipient-admin')->first();
        $this->assertNotNull($menu);
        $this->assertSame(NavigationItemType::Mega, $menu->item_type);
        $this->assertNull($menu->link_type);
        $this->assertSame(1, $menu->sections()->count());
        $this->assertSame('FOR HIM', $menu->sections->first()->heading);
        $this->assertSame('Gifts for Husband', $menu->sections->first()->links->first()->label);
        $this->assertSame(NavigationLinkType::Relationship, $menu->sections->first()->links->first()->link_type);
        $this->assertSame($husband->id, $menu->sections->first()->links->first()->linkable_id);
    }

    public function test_it_can_edit_a_menu_and_preserve_sections_and_links(): void
    {
        $this->actingAs(User::factory()->create());
        $menu = $this->persistedMegaMenu();

        Livewire::test(EditNavigationMenu::class, [
            'record' => $menu->getRouteKey(),
        ])
            ->fillForm([
                'label' => 'Recipients',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $menu->refresh();
        $this->assertSame('Recipients', $menu->label);
        $this->assertFalse($menu->is_active);
        $this->assertSame(1, $menu->sections()->count());
        $this->assertSame(1, $menu->sections->first()->links()->count());
    }

    public function test_section_and_link_order_follows_repeater_order(): void
    {
        $this->actingAs(User::factory()->create());
        $husband = $this->relationship();
        $father = Relationship::query()->create(['name' => 'Father', 'slug' => 'father', 'is_active' => true]);

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm([
                'label' => 'Ordered',
                'slug' => 'ordered',
                'item_type' => NavigationItemType::Mega->value,
                'sort_order' => 1,
                'is_active' => true,
                'sections' => [
                    [
                        'heading' => 'Second',
                        'appearance' => 'default',
                        'is_active' => true,
                        'links' => [
                            [
                                'label' => 'Father',
                                'link_type' => NavigationLinkType::Relationship->value,
                                'linkable_id' => $father->id,
                                'is_active' => true,
                            ],
                            [
                                'label' => 'Husband',
                                'link_type' => NavigationLinkType::Relationship->value,
                                'linkable_id' => $husband->id,
                                'is_active' => true,
                            ],
                        ],
                    ],
                    [
                        'heading' => 'First',
                        'appearance' => 'cta',
                        'is_active' => true,
                        'links' => [
                            [
                                'label' => 'Also husband',
                                'link_type' => NavigationLinkType::Relationship->value,
                                'linkable_id' => $husband->id,
                                'is_active' => true,
                            ],
                        ],
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $menu = NavigationMenu::query()->where('slug', 'ordered')->firstOrFail();
        $this->assertSame(['Second', 'First'], $menu->sections->pluck('heading')->all());
        $this->assertSame(['Father', 'Husband'], $menu->sections->first()->links->pluck('label')->all());
        $this->assertTrue($menu->sections->first()->sort_order < $menu->sections->last()->sort_order);
    }

    public function test_it_persists_each_supported_entity_link_type(): void
    {
        $this->actingAs(User::factory()->create());

        $targets = [
            [NavigationLinkType::Relationship, $this->relationship()->id],
            [NavigationLinkType::Occasion, Occasion::query()->create(['name' => 'Birthday', 'slug' => 'birthday', 'is_active' => true])->id],
            [NavigationLinkType::Interest, Interest::query()->create(['name' => 'Coffee', 'slug' => 'coffee', 'is_active' => true])->id],
            [NavigationLinkType::Profession, Profession::query()->create(['name' => 'Doctor', 'slug' => 'doctor', 'is_active' => true])->id],
            [NavigationLinkType::RecipientType, RecipientType::query()->create(['name' => 'Kids', 'slug' => 'kids', 'is_active' => true])->id],
            [NavigationLinkType::GiftType, GiftType::query()->create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'is_active' => true])->id],
            [NavigationLinkType::Category, Category::query()->create(['name' => 'Personalized', 'slug' => 'personalized', 'is_active' => true])->id],
        ];

        foreach ($targets as $index => [$type, $id]) {
            Livewire::test(CreateNavigationMenu::class)
                ->fillForm($this->megaForm($id, 'menu-'.$index, $type, 'Link '.$index))
                ->call('create')
                ->assertHasNoFormErrors();

            $link = NavigationMenu::query()->where('slug', 'menu-'.$index)->firstOrFail()
                ->sections->first()
                ->links->first();

            $this->assertSame($type, $link->link_type);
            $this->assertSame($id, $link->linkable_id);
            $this->assertNull($link->url);
            $this->assertNull($link->route_key);
        }
    }

    public function test_seo_landing_page_select_only_allows_discoverable_pages(): void
    {
        $this->actingAs(User::factory()->create());
        $published = SeoLandingPage::factory()->published()->create([
            'heading' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'is_indexable' => true,
        ]);
        $draft = SeoLandingPage::factory()->draft()->create([
            'heading' => 'Draft Page',
            'slug' => 'draft-page',
            'is_indexable' => true,
        ]);
        $noindex = SeoLandingPage::factory()->published()->create([
            'heading' => 'Noindex Page',
            'slug' => 'noindex-page',
            'is_indexable' => false,
        ]);

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm($this->megaForm($published->id, 'lp-ok', NavigationLinkType::SeoLandingPage, 'Birthday Gifts for Husband'))
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm($this->megaForm($draft->id, 'lp-draft', NavigationLinkType::SeoLandingPage, 'Draft'))
            ->call('create')
            ->assertHasFormErrors();

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm($this->megaForm($noindex->id, 'lp-noindex', NavigationLinkType::SeoLandingPage, 'Noindex'))
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(1, NavigationLink::query()->where('link_type', NavigationLinkType::SeoLandingPage)->count());
        $this->assertSame($published->id, NavigationLink::query()->where('link_type', NavigationLinkType::SeoLandingPage)->value('linkable_id'));
    }

    public function test_discovery_route_accepts_safe_keys_and_rejects_placeholders(): void
    {
        $this->actingAs(User::factory()->create());

        $options = ValidateNavigationTargetAction::selectableDiscoveryRoutes();
        $this->assertArrayHasKey('gift_ideas.index', $options);
        $this->assertArrayHasKey('finder.show', $options);
        $this->assertArrayNotHasKey('gift.show', $options);
        $this->assertArrayNotHasKey('relationship.show', $options);

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm($this->megaDiscoveryForm('gift_ideas.index', 'browse-ok'))
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm($this->megaDiscoveryForm('gift.show', 'browse-bad'))
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame('gift_ideas.index', NavigationLink::query()->where('link_type', NavigationLinkType::DiscoveryRoute)->value('route_key'));
    }

    public function test_external_url_must_be_absolute_http_or_https(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm($this->megaExternalForm('https://example.com/gifts', 'ext-ok'))
            ->call('create')
            ->assertHasNoFormErrors();

        foreach (['not-a-url', '/relative', 'javascript:alert(1)', '#anchor'] as $index => $invalid) {
            Livewire::test(CreateNavigationMenu::class)
                ->fillForm($this->megaExternalForm($invalid, 'ext-bad-'.$index))
                ->call('create')
                ->assertHasFormErrors();
        }

        $this->assertSame(1, NavigationLink::query()->where('link_type', NavigationLinkType::ExternalUrl)->count());
        $this->assertSame('https://example.com/gifts', NavigationLink::query()->where('link_type', NavigationLinkType::ExternalUrl)->value('url'));
    }

    public function test_top_level_link_menu_requires_a_valid_target(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm([
                'label' => 'Gift Ideas',
                'slug' => 'gift-ideas-link',
                'item_type' => NavigationItemType::Link->value,
                'sort_order' => 1,
                'is_active' => true,
                'link_type' => NavigationLinkType::DiscoveryRoute->value,
                'route_key' => 'gift_ideas.index',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $menu = NavigationMenu::query()->where('slug', 'gift-ideas-link')->firstOrFail();
        $this->assertSame(NavigationItemType::Link, $menu->item_type);
        $this->assertSame(NavigationLinkType::DiscoveryRoute, $menu->link_type);
        $this->assertSame('gift_ideas.index', $menu->route_key);
        $this->assertSame(0, $menu->sections()->count());

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm([
                'label' => 'Broken',
                'slug' => 'broken-link',
                'item_type' => NavigationItemType::Link->value,
                'sort_order' => 2,
                'is_active' => true,
                'link_type' => NavigationLinkType::ExternalUrl->value,
                'url' => 'not-a-url',
            ])
            ->call('create')
            ->assertHasFormErrors();
    }

    public function test_mega_menu_does_not_require_a_top_level_target(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm([
                'label' => 'Empty Mega',
                'slug' => 'empty-mega',
                'item_type' => NavigationItemType::Mega->value,
                'sort_order' => 1,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $menu = NavigationMenu::query()->where('slug', 'empty-mega')->firstOrFail();
        $this->assertNull($menu->link_type);
        $this->assertNull($menu->linkable_id);
        $this->assertNull($menu->route_key);
        $this->assertNull($menu->url);
    }

    public function test_invalid_entity_combinations_cannot_be_saved(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm($this->megaForm(null, 'missing-target', NavigationLinkType::Relationship, 'Missing'))
            ->call('create')
            ->assertHasFormErrors();

        $inactive = Relationship::query()->create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm($this->megaForm($inactive->id, 'inactive-target'))
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(0, NavigationMenu::query()->whereIn('slug', ['missing-target', 'inactive-target'])->count());
    }

    public function test_saved_navigation_resolves_through_the_tree_builder(): void
    {
        $this->actingAs(User::factory()->create());
        $husband = $this->relationship();

        Livewire::test(CreateNavigationMenu::class)
            ->fillForm($this->megaForm($husband->id))
            ->call('create')
            ->assertHasNoFormErrors();

        $tree = app(BuildPrimaryNavigationTreeAction::class)->execute();
        $menu = collect($tree)->firstWhere('slug', 'by-recipient-admin');

        $this->assertNotNull($menu);
        $this->assertSame(DiscoveryUrl::relationship('husband'), $menu['sections'][0]['links'][0]['href']);
    }

    public function test_saving_and_deleting_invalidates_the_navigation_cache(): void
    {
        $this->actingAs(User::factory()->create());
        $menu = $this->persistedMegaMenu();

        Cache::forever(BuildPrimaryNavigationTreeAction::CACHE_KEY, ['stale']);

        Livewire::test(EditNavigationMenu::class, [
            'record' => $menu->getRouteKey(),
        ])
            ->fillForm(['label' => 'Updated cache'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(Cache::has(BuildPrimaryNavigationTreeAction::CACHE_KEY));

        Cache::forever(BuildPrimaryNavigationTreeAction::CACHE_KEY, ['stale']);

        Livewire::test(EditNavigationMenu::class, [
            'record' => $menu->getRouteKey(),
        ])->callAction('delete');

        $this->assertFalse(Cache::has(BuildPrimaryNavigationTreeAction::CACHE_KEY));
        $this->assertDatabaseMissing('navigation_menus', ['id' => $menu->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function megaForm(
        ?int $linkableId,
        string $slug = 'by-recipient-admin',
        NavigationLinkType $linkType = NavigationLinkType::Relationship,
        string $linkLabel = 'Gifts for Husband',
    ): array {
        return [
            'label' => 'By Recipient',
            'slug' => $slug,
            'item_type' => NavigationItemType::Mega->value,
            'sort_order' => 1,
            'is_active' => true,
            'sections' => [
                [
                    'heading' => 'FOR HIM',
                    'appearance' => 'default',
                    'is_active' => true,
                    'links' => [
                        [
                            'label' => $linkLabel,
                            'link_type' => $linkType->value,
                            'linkable_id' => $linkableId,
                            'is_active' => true,
                            'opens_in_new_tab' => false,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function megaDiscoveryForm(string $routeKey, string $slug): array
    {
        return [
            'label' => 'Browse',
            'slug' => $slug,
            'item_type' => NavigationItemType::Mega->value,
            'sort_order' => 1,
            'is_active' => true,
            'sections' => [
                [
                    'heading' => 'BROWSE ALL',
                    'appearance' => 'cta',
                    'is_active' => true,
                    'links' => [
                        [
                            'label' => 'View all',
                            'link_type' => NavigationLinkType::DiscoveryRoute->value,
                            'route_key' => $routeKey,
                            'is_active' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function megaExternalForm(string $url, string $slug): array
    {
        return [
            'label' => 'External',
            'slug' => $slug,
            'item_type' => NavigationItemType::Mega->value,
            'sort_order' => 1,
            'is_active' => true,
            'sections' => [
                [
                    'heading' => 'PARTNERS',
                    'appearance' => 'default',
                    'is_active' => true,
                    'links' => [
                        [
                            'label' => 'Partner',
                            'link_type' => NavigationLinkType::ExternalUrl->value,
                            'url' => $url,
                            'opens_in_new_tab' => true,
                            'is_active' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function persistedMegaMenu(): NavigationMenu
    {
        $husband = $this->relationship();
        $menu = NavigationMenu::factory()->mega()->create([
            'label' => 'By Recipient',
            'slug' => 'by-recipient-admin',
        ]);
        $section = NavigationSection::factory()->create([
            'navigation_menu_id' => $menu->id,
            'heading' => 'FOR HIM',
        ]);
        NavigationLink::factory()->create([
            'navigation_section_id' => $section->id,
            'label' => 'Gifts for Husband',
            'link_type' => NavigationLinkType::Relationship,
            'linkable_id' => $husband->id,
        ]);

        return $menu->fresh(['sections.links']);
    }

    private function relationship(): Relationship
    {
        return Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);
    }
}
