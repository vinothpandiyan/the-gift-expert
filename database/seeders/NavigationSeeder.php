<?php

namespace Database\Seeders;

use App\Actions\Navigation\BuildPrimaryNavigationTreeAction;
use App\Enums\NavigationItemType;
use App\Enums\NavigationLinkType;
use App\Enums\NavigationSectionAppearance;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class NavigationSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedByRecipient();
        $this->seedByOccasion();
        $this->seedByInterest();
        $this->seedByProfession();
        $this->seedDigitalGifts();
        $this->seedReturnGifts();

        Cache::forget(BuildPrimaryNavigationTreeAction::CACHE_KEY);
    }

    private function seedByRecipient(): void
    {
        $menu = $this->upsertMenu('by-recipient', 'By Recipient', 1);

        $this->upsertSectionWithLinks($menu, 'FOR HIM', 1, [
            ['Gifts for Husband', NavigationLinkType::Relationship, Relationship::class, 'husband'],
            ['Gifts for Boyfriend', NavigationLinkType::Relationship, Relationship::class, 'boyfriend'],
            ['Gifts for Dad', NavigationLinkType::Relationship, Relationship::class, 'father'],
            ['Gifts for Brother', NavigationLinkType::Relationship, Relationship::class, 'brother'],
            ['Gifts for Son', NavigationLinkType::Relationship, Relationship::class, 'son'],
        ]);

        $this->upsertSectionWithLinks($menu, 'FOR HER', 2, [
            ['Gifts for Wife', NavigationLinkType::Relationship, Relationship::class, 'wife'],
            ['Gifts for Girlfriend', NavigationLinkType::Relationship, Relationship::class, 'girlfriend'],
            ['Gifts for Mom', NavigationLinkType::Relationship, Relationship::class, 'mother'],
            ['Gifts for Sister', NavigationLinkType::Relationship, Relationship::class, 'sister'],
            ['Gifts for Daughter', NavigationLinkType::Relationship, Relationship::class, 'daughter'],
        ]);

        $this->upsertSectionWithLinks($menu, 'FAMILY', 3, [
            ['Gifts for Kids', NavigationLinkType::RecipientType, RecipientType::class, 'kids'],
            ['Gifts for Parents', NavigationLinkType::Relationship, Relationship::class, 'parents'],
            ['Gifts for Grandparents', NavigationLinkType::Relationship, Relationship::class, 'grandparents'],
            ['Gifts for Newlyweds', NavigationLinkType::Relationship, Relationship::class, 'newlyweds'],
            ['Gifts for Colleagues', NavigationLinkType::Relationship, Relationship::class, 'colleagues'],
        ]);

        $this->upsertSectionWithLinks($menu, 'SPECIAL', 4, [
            ['Gifts for Boss', NavigationLinkType::Relationship, Relationship::class, 'boss'],
            ['Gifts for Friends', NavigationLinkType::Relationship, Relationship::class, 'friends'],
            ['Eco-friendly gifts', NavigationLinkType::Interest, Interest::class, 'eco-friendly'],
        ]);

        $this->upsertBrowseAll($menu, 'View all recipients');
    }

    private function seedByOccasion(): void
    {
        $menu = $this->upsertMenu('by-occasion', 'By Occasion', 2);

        $this->upsertSectionWithLinks($menu, 'POPULAR', 1, [
            ['Birthday Gifts', NavigationLinkType::Occasion, Occasion::class, 'birthday'],
            ['Anniversary Gifts', NavigationLinkType::Occasion, Occasion::class, 'anniversary'],
            ['Wedding Gifts', NavigationLinkType::Occasion, Occasion::class, 'wedding'],
            ['Engagement Gifts', NavigationLinkType::Occasion, Occasion::class, 'engagement'],
        ]);

        $this->upsertSectionWithLinks($menu, 'LIFE EVENTS', 2, [
            ['Baby Shower Gifts', NavigationLinkType::Occasion, Occasion::class, 'baby-shower'],
            ['Housewarming Gifts', NavigationLinkType::Occasion, Occasion::class, 'housewarming'],
            ['Farewell Gifts', NavigationLinkType::Occasion, Occasion::class, 'farewell'],
            ['Retirement Gifts', NavigationLinkType::Occasion, Occasion::class, 'retirement'],
        ]);

        $this->upsertSectionWithLinks($menu, 'FESTIVALS', 3, [
            ['Diwali Gifts', NavigationLinkType::Occasion, Occasion::class, 'diwali'],
            ['Pongal Gifts', NavigationLinkType::Occasion, Occasion::class, 'pongal'],
            ['Raksha Bandhan Gifts', NavigationLinkType::Occasion, Occasion::class, 'raksha-bandhan'],
            ['Eid Gifts', NavigationLinkType::Occasion, Occasion::class, 'eid'],
            ['Christmas Gifts', NavigationLinkType::Occasion, Occasion::class, 'christmas'],
            ['New Year Gifts', NavigationLinkType::Occasion, Occasion::class, 'new-year'],
        ]);

        $this->upsertSectionWithLinks($menu, 'OTHER', 4, [
            ['Festival Gifts', NavigationLinkType::Occasion, Occasion::class, 'festival'],
        ]);

        $this->upsertBrowseAll($menu, 'View all occasions');
    }

    private function seedByInterest(): void
    {
        $menu = $this->upsertMenu('by-interest', 'By Interest', 3);

        $this->upsertSectionWithLinks($menu, 'FOOD & DRINK', 1, [
            ['Gifts for Food Lovers', NavigationLinkType::Interest, Interest::class, 'food'],
            ['Gifts for Coffee Lovers', NavigationLinkType::Interest, Interest::class, 'coffee'],
        ]);

        $this->upsertSectionWithLinks($menu, 'ACTIVE', 2, [
            ['Gifts for Fitness Lovers', NavigationLinkType::Interest, Interest::class, 'fitness'],
            ['Gifts for Travel Lovers', NavigationLinkType::Interest, Interest::class, 'travel'],
            ['Gifts for Pet Lovers', NavigationLinkType::Interest, Interest::class, 'pets'],
        ]);

        $this->upsertSectionWithLinks($menu, 'CREATIVE', 3, [
            ['Gifts for Book Lovers', NavigationLinkType::Interest, Interest::class, 'books'],
            ['Gifts for Music Lovers', NavigationLinkType::Interest, Interest::class, 'music'],
            ['Gifts for Photography', NavigationLinkType::Interest, Interest::class, 'photography'],
        ]);

        $this->upsertSectionWithLinks($menu, 'TECH & DIGITAL', 4, [
            ['Gifts for Tech Lovers', NavigationLinkType::Interest, Interest::class, 'technology'],
            ['Eco-friendly gifts', NavigationLinkType::Interest, Interest::class, 'eco-friendly'],
        ]);

        $this->upsertBrowseAll($menu, 'View all interests');
    }

    private function seedByProfession(): void
    {
        $menu = $this->upsertMenu('by-profession', 'By Profession', 4);

        $this->upsertSectionWithLinks($menu, 'HEALTHCARE', 1, [
            ['Gifts for Doctor', NavigationLinkType::Profession, Profession::class, 'doctor'],
        ]);

        $this->upsertSectionWithLinks($menu, 'TECH', 2, [
            ['Gifts for Engineer', NavigationLinkType::Profession, Profession::class, 'engineer'],
            ['Gifts for Software Developer', NavigationLinkType::Profession, Profession::class, 'software-developer'],
            ['Gifts for Designer', NavigationLinkType::Profession, Profession::class, 'designer'],
        ]);

        $this->upsertSectionWithLinks($menu, 'BUSINESS', 3, [
            ['Gifts for Business Owner', NavigationLinkType::Profession, Profession::class, 'business-owner'],
            ['Gifts for CA / Finance', NavigationLinkType::Profession, Profession::class, 'ca-finance'],
            ['Gifts for Content Creator', NavigationLinkType::Profession, Profession::class, 'content-creator'],
        ]);

        $this->upsertSectionWithLinks($menu, 'EDUCATION', 4, [
            ['Gifts for Teacher', NavigationLinkType::Profession, Profession::class, 'teacher'],
        ]);

        $this->upsertBrowseAll($menu, 'View all professions');
    }

    private function seedDigitalGifts(): void
    {
        $menu = $this->upsertMenu('digital-gifts', 'Digital Gifts', 5);

        $this->upsertSectionWithLinks($menu, 'INSTANT', 1, [
            ['Gift Cards', NavigationLinkType::GiftType, GiftType::class, 'gift-cards'],
            ['Subscriptions', NavigationLinkType::GiftType, GiftType::class, 'subscriptions'],
            ['Instant Digital Gifts', NavigationLinkType::GiftType, GiftType::class, 'digital-instant-gifts'],
        ]);

        $this->upsertSectionWithLinks($menu, 'LEARNING', 2, [
            ['Online Course Gifts', NavigationLinkType::GiftType, GiftType::class, 'online-courses'],
            ['E-books & Audiobooks', NavigationLinkType::GiftType, GiftType::class, 'ebooks-audiobooks'],
        ]);

        $this->upsertBrowseAll($menu, 'View all digital gifts');
    }

    private function seedReturnGifts(): void
    {
        $menu = $this->upsertMenu('return-gifts', 'Return Gifts', 6);

        $this->deactivateObsoleteReturnGiftSections($menu);

        $this->upsertSeoLandingPageSection($menu, 'BY EVENT', 1, [
            ['Birthday return gifts', 'birthday-return-gifts'],
            ['Wedding return gifts', 'wedding-return-gifts'],
            ['Baby shower return gifts', 'baby-shower-return-gifts'],
            ['Engagement return gifts', 'engagement-return-gifts'],
        ]);

        $this->upsertEmptySection($menu, 'CORPORATE', 2);

        $this->upsertSeoLandingPageSection($menu, 'BY BUDGET', 3, [
            ['Return gifts under ₹500', 'return-gifts-under-500'],
        ]);

        $this->upsertReturnGiftsHub($menu);
    }

    private function deactivateObsoleteReturnGiftSections(NavigationMenu $menu): void
    {
        $keep = ['BY EVENT', 'CORPORATE', 'BY BUDGET', 'BROWSE ALL'];

        $obsolete = $menu->sections()->whereNotIn('heading', $keep)->get();

        foreach ($obsolete as $section) {
            $section->links()->update(['is_active' => false]);
            $section->update(['is_active' => false]);
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $definitions
     */
    private function upsertSeoLandingPageSection(
        NavigationMenu $menu,
        string $heading,
        int $sortOrder,
        array $definitions,
    ): void {
        $section = NavigationSection::query()->updateOrCreate(
            [
                'navigation_menu_id' => $menu->id,
                'heading' => $heading,
            ],
            [
                'appearance' => NavigationSectionAppearance::Default,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ],
        );

        foreach ($definitions as $sort => [$label, $slug]) {
            $id = SeoLandingPage::query()->where('slug', $slug)->value('id');

            if ($id === null) {
                continue;
            }

            NavigationLink::query()->updateOrCreate(
                [
                    'navigation_section_id' => $section->id,
                    'link_type' => NavigationLinkType::SeoLandingPage,
                    'linkable_id' => (int) $id,
                ],
                [
                    'label' => $label,
                    'sort_order' => $sort + 1,
                    'is_active' => true,
                    'opens_in_new_tab' => false,
                    'route_key' => null,
                    'url' => null,
                ],
            );
        }
    }

    private function upsertEmptySection(NavigationMenu $menu, string $heading, int $sortOrder): void
    {
        NavigationSection::query()->updateOrCreate(
            [
                'navigation_menu_id' => $menu->id,
                'heading' => $heading,
            ],
            [
                'appearance' => NavigationSectionAppearance::Default,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ],
        );
    }

    private function upsertReturnGiftsHub(NavigationMenu $menu): void
    {
        $giftTypeId = $this->activeId(GiftType::class, 'return-gifts');

        if ($giftTypeId === null) {
            return;
        }

        $section = NavigationSection::query()->updateOrCreate(
            [
                'navigation_menu_id' => $menu->id,
                'heading' => 'BROWSE ALL',
            ],
            [
                'appearance' => NavigationSectionAppearance::Cta,
                'sort_order' => 99,
                'is_active' => true,
            ],
        );

        NavigationLink::query()
            ->where('navigation_section_id', $section->id)
            ->where('link_type', NavigationLinkType::DiscoveryRoute)
            ->update(['is_active' => false]);

        NavigationLink::query()->updateOrCreate(
            [
                'navigation_section_id' => $section->id,
                'link_type' => NavigationLinkType::GiftType,
                'linkable_id' => $giftTypeId,
            ],
            [
                'label' => 'View all return gifts',
                'sort_order' => 1,
                'is_active' => true,
                'opens_in_new_tab' => false,
                'route_key' => null,
                'url' => null,
            ],
        );
    }

    private function upsertMenu(string $slug, string $label, int $sortOrder): NavigationMenu
    {
        return NavigationMenu::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'label' => $label,
                'item_type' => NavigationItemType::Mega,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'link_type' => null,
                'linkable_id' => null,
                'route_key' => null,
                'url' => null,
                'opens_in_new_tab' => false,
            ],
        );
    }

    /**
     * @param  list<array{0: string, 1: NavigationLinkType, 2: class-string<Model>, 3: string}>  $definitions
     */
    private function upsertSectionWithLinks(
        NavigationMenu $menu,
        string $heading,
        int $sortOrder,
        array $definitions,
    ): void {
        $resolved = [];

        foreach ($definitions as $sort => [$label, $linkType, $modelClass, $slug]) {
            $id = $this->activeId($modelClass, $slug);

            if ($id === null) {
                continue;
            }

            $resolved[] = [
                'label' => $label,
                'link_type' => $linkType,
                'linkable_id' => $id,
                'sort_order' => $sort + 1,
            ];
        }

        if ($resolved === []) {
            return;
        }

        $section = NavigationSection::query()->updateOrCreate(
            [
                'navigation_menu_id' => $menu->id,
                'heading' => $heading,
            ],
            [
                'appearance' => NavigationSectionAppearance::Default,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ],
        );

        foreach ($resolved as $link) {
            NavigationLink::query()->updateOrCreate(
                [
                    'navigation_section_id' => $section->id,
                    'link_type' => $link['link_type'],
                    'linkable_id' => $link['linkable_id'],
                ],
                [
                    'label' => $link['label'],
                    'sort_order' => $link['sort_order'],
                    'is_active' => true,
                    'opens_in_new_tab' => false,
                    'route_key' => null,
                    'url' => null,
                ],
            );
        }
    }

    private function upsertBrowseAll(NavigationMenu $menu, string $label): void
    {
        $section = NavigationSection::query()->updateOrCreate(
            [
                'navigation_menu_id' => $menu->id,
                'heading' => 'BROWSE ALL',
            ],
            [
                'appearance' => NavigationSectionAppearance::Cta,
                'sort_order' => 99,
                'is_active' => true,
            ],
        );

        NavigationLink::query()->updateOrCreate(
            [
                'navigation_section_id' => $section->id,
                'link_type' => NavigationLinkType::DiscoveryRoute,
                'route_key' => 'gift_ideas.index',
            ],
            [
                'label' => $label,
                'sort_order' => 1,
                'is_active' => true,
                'opens_in_new_tab' => false,
                'linkable_id' => null,
                'url' => null,
            ],
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function activeId(string $modelClass, string $slug): ?int
    {
        $id = $modelClass::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
