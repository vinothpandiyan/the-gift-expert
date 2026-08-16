<?php

namespace Tests\Unit\Actions;

use App\Actions\Navigation\ResolveNavigationHrefAction;
use App\Enums\NavigationLinkType;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveNavigationHrefActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_relationship_through_discovery_url(): void
    {
        $relationship = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);

        $this->assertSame(
            DiscoveryUrl::relationship('husband'),
            $this->href(NavigationLinkType::Relationship, $relationship->id),
        );
        $this->assertSame('/gifts-for/husband', $this->href(NavigationLinkType::Relationship, $relationship->id));
    }

    public function test_it_resolves_occasion_through_discovery_url(): void
    {
        $occasion = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);

        $this->assertSame(
            DiscoveryUrl::occasion('birthday'),
            $this->href(NavigationLinkType::Occasion, $occasion->id),
        );
    }

    public function test_it_resolves_interest_through_discovery_url(): void
    {
        $interest = Interest::query()->create([
            'name' => 'Coffee',
            'slug' => 'coffee',
            'is_active' => true,
        ]);

        $this->assertSame(
            DiscoveryUrl::interest('coffee'),
            $this->href(NavigationLinkType::Interest, $interest->id),
        );
    }

    public function test_it_resolves_profession_through_discovery_url(): void
    {
        $profession = Profession::query()->create([
            'name' => 'Doctor',
            'slug' => 'doctor',
            'is_active' => true,
        ]);

        $this->assertSame(
            DiscoveryUrl::profession('doctor'),
            $this->href(NavigationLinkType::Profession, $profession->id),
        );
    }

    public function test_it_resolves_recipient_type_through_discovery_url(): void
    {
        $recipientType = RecipientType::query()->create([
            'name' => 'Kids',
            'slug' => 'kids',
            'is_active' => true,
        ]);

        $this->assertSame(
            DiscoveryUrl::recipientType('kids'),
            $this->href(NavigationLinkType::RecipientType, $recipientType->id),
        );
    }

    public function test_it_resolves_gift_type_through_discovery_url(): void
    {
        $giftType = GiftType::query()->create([
            'name' => 'Gift Cards',
            'slug' => 'gift-cards',
            'is_active' => true,
        ]);

        $this->assertSame(
            DiscoveryUrl::giftType('gift-cards'),
            $this->href(NavigationLinkType::GiftType, $giftType->id),
        );
    }

    public function test_it_resolves_category_using_full_path_not_seo_landing_page(): void
    {
        $page = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-husband',
            'is_indexable' => true,
        ]);
        $category = Category::query()->create([
            'name' => 'Personalized Gifts',
            'slug' => 'personalized-gifts',
            'is_active' => true,
            'canonical_seo_landing_page_id' => $page->id,
        ]);

        $href = $this->href(NavigationLinkType::Category, $category->id);

        $this->assertSame(DiscoveryUrl::giftIdeasCategory($category->full_path), $href);
        $this->assertSame('/gift-ideas/personalized-gifts', $href);
        $this->assertNotSame(DiscoveryUrl::seoLandingPage($page->slug), $href);
    }

    public function test_it_resolves_discoverable_seo_landing_page_to_slug_path(): void
    {
        $page = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-husband',
            'is_indexable' => true,
        ]);

        $this->assertSame(
            DiscoveryUrl::seoLandingPage('birthday-gifts-for-husband'),
            $this->href(NavigationLinkType::SeoLandingPage, $page->id),
        );
        $this->assertSame('/birthday-gifts-for-husband', $this->href(NavigationLinkType::SeoLandingPage, $page->id));
    }

    public function test_it_resolves_discovery_route_without_placeholders(): void
    {
        $this->assertSame(
            DiscoveryUrl::giftIdeas(),
            $this->href(NavigationLinkType::DiscoveryRoute, routeKey: 'gift_ideas.index'),
        );
    }

    public function test_it_resolves_valid_http_and_https_external_urls(): void
    {
        $this->assertSame(
            'https://example.com/gifts',
            $this->href(NavigationLinkType::ExternalUrl, url: 'https://example.com/gifts'),
        );
        $this->assertSame(
            'http://example.com/gifts',
            $this->href(NavigationLinkType::ExternalUrl, url: 'http://example.com/gifts'),
        );
    }

    public function test_it_returns_null_for_missing_entity(): void
    {
        $this->assertNull($this->href(NavigationLinkType::Relationship, 99999));
        $this->assertNull($this->href(NavigationLinkType::Relationship, null));
        $this->assertNull($this->href(NavigationLinkType::SeoLandingPage, 99999));
    }

    public function test_it_returns_null_for_inactive_entity(): void
    {
        $relationship = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => false,
        ]);
        $category = Category::query()->create([
            'name' => 'Hidden',
            'slug' => 'hidden',
            'is_active' => false,
        ]);

        $this->assertNull($this->href(NavigationLinkType::Relationship, $relationship->id));
        $this->assertNull($this->href(NavigationLinkType::Category, $category->id));
    }

    public function test_it_returns_null_for_soft_deleted_entity(): void
    {
        $relationship = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);
        $page = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-husband',
            'is_indexable' => true,
        ]);

        $relationship->delete();
        $page->delete();

        $this->assertNull($this->href(NavigationLinkType::Relationship, $relationship->id));
        $this->assertNull($this->href(NavigationLinkType::SeoLandingPage, $page->id));
    }

    public function test_it_returns_null_for_draft_seo_landing_page(): void
    {
        $page = SeoLandingPage::factory()->draft()->create([
            'slug' => 'draft-page',
            'is_indexable' => true,
        ]);

        $this->assertNull($this->href(NavigationLinkType::SeoLandingPage, $page->id));
    }

    public function test_it_returns_null_for_noindex_seo_landing_page(): void
    {
        $page = SeoLandingPage::factory()->published()->create([
            'slug' => 'noindex-page',
            'is_indexable' => false,
        ]);

        $this->assertNull($this->href(NavigationLinkType::SeoLandingPage, $page->id));
    }

    public function test_it_returns_null_for_invalid_discovery_route(): void
    {
        $this->assertNull($this->href(NavigationLinkType::DiscoveryRoute, routeKey: 'missing.route'));
        $this->assertNull($this->href(NavigationLinkType::DiscoveryRoute, routeKey: null));
    }

    public function test_it_returns_null_for_unresolved_route_placeholder(): void
    {
        $this->assertNull($this->href(NavigationLinkType::DiscoveryRoute, routeKey: 'relationship.show'));
        $this->assertNull($this->href(NavigationLinkType::DiscoveryRoute, routeKey: 'gift.show'));
    }

    public function test_it_returns_null_for_invalid_external_url(): void
    {
        $this->assertNull($this->href(NavigationLinkType::ExternalUrl, url: 'not-a-url'));
        $this->assertNull($this->href(NavigationLinkType::ExternalUrl, url: '/relative/path'));
        $this->assertNull($this->href(NavigationLinkType::ExternalUrl, url: 'javascript:alert(1)'));
        $this->assertNull($this->href(NavigationLinkType::ExternalUrl, url: null));
    }

    public function test_it_returns_null_when_link_type_is_missing(): void
    {
        $this->assertNull($this->href(null));
    }

    private function href(
        ?NavigationLinkType $linkType,
        ?int $linkableId = null,
        ?string $routeKey = null,
        ?string $url = null,
    ): ?string {
        return app(ResolveNavigationHrefAction::class)->execute(
            $linkType,
            $linkableId,
            $routeKey,
            $url,
        );
    }
}
