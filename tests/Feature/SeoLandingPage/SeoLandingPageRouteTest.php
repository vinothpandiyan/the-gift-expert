<?php

namespace Tests\Feature\SeoLandingPage;

use App\Enums\SeoLandingPageStatus;
use App\Models\Category;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Models\SeoLandingPageRedirect;
use App\Support\DiscoveryUrl;
use App\Support\Terminology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Discovery\GiftCatalogTestHelpers;
use Tests\TestCase;

class SeoLandingPageRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_relationship_url_still_resolves_to_taxonomy(): void
    {
        Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);

        $this->get(DiscoveryUrl::relationship('husband'))
            ->assertOk()
            ->assertSee('Husband', false)
            ->assertSee('Relationship', false);
    }

    public function test_existing_occasion_url_still_resolves_to_taxonomy(): void
    {
        Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);

        $this->get(DiscoveryUrl::occasion('birthday'))
            ->assertOk()
            ->assertSee('Birthday', false)
            ->assertSee('Occasion', false);
    }

    public function test_existing_gift_url_still_resolves_to_product(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Wooden Frame',
            'slug' => 'wooden-frame',
        ]);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee('Wooden Frame', false);
    }

    public function test_existing_category_url_still_resolves_to_category(): void
    {
        $category = Category::query()->create([
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true,
        ]);

        $this->get(DiscoveryUrl::giftIdeasCategory($category->fresh()->full_path))
            ->assertOk()
            ->assertSee('Electronics', false);
    }

    public function test_existing_finder_url_still_resolves(): void
    {
        $this->get(DiscoveryUrl::finder())
            ->assertOk()
            ->assertSee('Find a Gift', false);
    }

    public function test_published_landing_page_resolves(): void
    {
        $page = $this->publishedLandingPage(
            slug: 'birthday-gifts-for-husband',
            heading: 'Birthday Gifts for Husband',
        );

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertOk()
            ->assertSee('Birthday Gifts for Husband', false);
    }

    public function test_draft_landing_page_returns_not_found(): void
    {
        $page = SeoLandingPage::factory()->draft()->create([
            'slug' => 'draft-birthday-gifts-for-husband',
            'relationship_id' => $this->relationship()->id,
        ]);

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertNotFound();
    }

    public function test_unpublished_landing_page_returns_not_found(): void
    {
        $page = $this->publishedLandingPage(slug: 'unpublished-birthday-gifts-for-husband');

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertOk();

        $page->update([
            'status' => SeoLandingPageStatus::Draft,
        ]);

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertNotFound();
    }

    public function test_soft_deleted_landing_page_returns_not_found(): void
    {
        $page = $this->publishedLandingPage(slug: 'deleted-birthday-gifts-for-husband');
        $page->delete();

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertNotFound();
    }

    public function test_reserved_slug_gifts_cannot_resolve_as_landing_page(): void
    {
        SeoLandingPage::factory()->published()->create([
            'slug' => 'gifts',
            'heading' => 'Reserved Gifts',
            'relationship_id' => $this->relationship()->id,
        ]);

        $this->get('/gifts')->assertNotFound();
    }

    public function test_reserved_slug_gifts_for_cannot_resolve_as_landing_page(): void
    {
        SeoLandingPage::factory()->published()->create([
            'slug' => 'gifts-for',
            'heading' => 'Reserved Gifts For',
            'relationship_id' => $this->relationship()->id,
        ]);

        $this->get('/gifts-for')->assertNotFound();
    }

    public function test_compound_gifts_for_boyfriend_slug_is_allowed(): void
    {
        $page = $this->publishedLandingPage(
            slug: 'gifts-for-boyfriend',
            heading: 'Gifts for Boyfriend',
        );

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertOk()
            ->assertSee('Gifts for Boyfriend', false);
    }

    public function test_compound_gifts_for_husband_slug_is_allowed(): void
    {
        $page = $this->publishedLandingPage(
            slug: 'gifts-for-husband',
            heading: 'Gifts for Husband',
        );

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertOk()
            ->assertSee('Gifts for Husband', false);
    }

    public function test_old_slug_redirects_to_published_target(): void
    {
        $page = $this->publishedLandingPage(slug: 'birthday-gifts-for-husband');

        SeoLandingPageRedirect::query()->create([
            'from_slug' => 'old-birthday-gifts-for-husband',
            'to_slug' => $page->slug,
            'seo_landing_page_id' => $page->id,
        ]);

        $this->get(DiscoveryUrl::seoLandingPage('old-birthday-gifts-for-husband'))
            ->assertStatus(301)
            ->assertRedirect(DiscoveryUrl::seoLandingPage($page->slug));
    }

    public function test_redirect_to_draft_target_returns_not_found(): void
    {
        $draft = SeoLandingPage::factory()->draft()->create([
            'slug' => 'draft-target',
            'relationship_id' => $this->relationship()->id,
        ]);

        SeoLandingPageRedirect::query()->create([
            'from_slug' => 'old-draft-target',
            'to_slug' => $draft->slug,
            'seo_landing_page_id' => $draft->id,
        ]);

        $this->get(DiscoveryUrl::seoLandingPage('old-draft-target'))
            ->assertNotFound();
    }

    public function test_redirect_to_deleted_target_returns_not_found(): void
    {
        $page = $this->publishedLandingPage(slug: 'deleted-target');
        $page->delete();

        SeoLandingPageRedirect::query()->create([
            'from_slug' => 'old-deleted-target',
            'to_slug' => 'deleted-target',
        ]);

        $this->get(DiscoveryUrl::seoLandingPage('old-deleted-target'))
            ->assertNotFound();
    }

    public function test_redirect_to_missing_target_returns_not_found(): void
    {
        SeoLandingPageRedirect::query()->create([
            'from_slug' => 'old-missing-target',
            'to_slug' => 'does-not-exist',
        ]);

        $this->get(DiscoveryUrl::seoLandingPage('old-missing-target'))
            ->assertNotFound();
    }

    public function test_published_indexable_page_uses_index_follow(): void
    {
        $page = $this->publishedLandingPage(
            slug: 'indexable-birthday-gifts-for-husband',
            attributes: ['is_indexable' => true],
        );

        $canonical = DiscoveryUrl::seoLandingPage($page->slug, absolute: true);

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertOk()
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('<link rel="canonical" href="'.$canonical.'">', false);
    }

    public function test_published_non_indexable_page_uses_noindex_follow(): void
    {
        $page = $this->publishedLandingPage(
            slug: 'noindex-birthday-gifts-for-husband',
            attributes: ['is_indexable' => false],
        );

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_canonical_url_override_is_used(): void
    {
        $page = $this->publishedLandingPage(
            slug: 'canonical-override-page',
            attributes: [
                'meta_title' => 'Custom Landing Title',
                'meta_description' => 'Custom landing description',
                'canonical_url' => 'https://cdn.example.test/birthday-gifts-for-husband',
            ],
        );

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertOk()
            ->assertSee('<title>Custom Landing Title</title>', false)
            ->assertSee('<meta name="description" content="Custom landing description">', false)
            ->assertSee('<link rel="canonical" href="https://cdn.example.test/birthday-gifts-for-husband">', false);
    }

    public function test_landing_page_does_not_canonicalize_to_relationship_url(): void
    {
        $relationship = $this->relationship();
        $page = $this->publishedLandingPage(
            slug: 'birthday-gifts-for-husband',
            relationship: $relationship,
        );

        $response = $this->get(DiscoveryUrl::seoLandingPage($page->slug));

        $response->assertOk();
        $response->assertSee(
            '<link rel="canonical" href="'.DiscoveryUrl::seoLandingPage($page->slug, absolute: true).'">',
            false,
        );
        $response->assertDontSee(
            '<link rel="canonical" href="'.DiscoveryUrl::relationship($relationship->slug, absolute: true).'">',
            false,
        );
    }

    public function test_landing_page_lists_products_matching_hard_filters(): void
    {
        $husband = $this->relationship();
        $birthday = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
        ]);

        $matching = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Matching Husband Birthday Gift',
            'slug' => 'matching-husband-birthday',
        ]);
        $matching->relationships()->attach($husband);
        $matching->occasions()->attach($birthday);

        $husbandOnly = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Husband Only Gift',
            'slug' => 'husband-only-gift',
        ]);
        $husbandOnly->relationships()->attach($husband);

        $this->publishedLandingPage(
            slug: 'birthday-gifts-for-husband',
            heading: 'Birthday Gifts for Husband',
            relationship: $husband,
            attributes: ['occasion_id' => $birthday->id],
        );

        $this->get(DiscoveryUrl::seoLandingPage('birthday-gifts-for-husband'))
            ->assertOk()
            ->assertSee('Matching Husband Birthday Gift', false)
            ->assertDontSee('Husband Only Gift', false);
    }

    public function test_empty_matching_catalog_still_returns_ok(): void
    {
        $page = $this->publishedLandingPage(
            slug: 'empty-birthday-gifts-for-husband',
            attributes: ['is_indexable' => true],
        );

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertOk()
            ->assertSee('No '.strtolower(Terminology::gifts()).' found yet.', false)
            ->assertSee('<meta name="robots" content="index, follow">', false);
    }

    public function test_sitemap_flag_does_not_change_http_robots(): void
    {
        $page = $this->publishedLandingPage(
            slug: 'sitemap-flag-page',
            attributes: [
                'is_indexable' => false,
                'include_in_sitemap' => true,
            ],
        );

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_pagination_canonicals_include_page_query(): void
    {
        $husband = $this->relationship();

        foreach (range(1, 13) as $index) {
            $gift = GiftCatalogTestHelpers::publishedGift([
                'name' => "Husband Gift {$index}",
                'slug' => "husband-gift-{$index}",
            ]);
            $gift->relationships()->attach($husband);
        }

        $page = $this->publishedLandingPage(
            slug: 'gifts-for-husbands-list',
            heading: 'Gifts for Husbands',
            relationship: $husband,
        );

        $baseCanonical = DiscoveryUrl::seoLandingPage($page->slug, absolute: true);

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.$baseCanonical.'">', false)
            ->assertSee('<link rel="next"', false);

        $this->get(DiscoveryUrl::seoLandingPage($page->slug).'?page=2')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.$baseCanonical.'?page=2">', false)
            ->assertSee('<link rel="prev"', false);
    }

    public function test_reused_slug_serves_the_new_published_page_instead_of_the_old_redirect(): void
    {
        $page = $this->publishedLandingPage(slug: 'old-birthday-gifts-for-husband');
        $page->update(['slug' => 'birthday-gifts-for-husband']);

        $replacement = $this->publishedLandingPage(
            slug: 'old-birthday-gifts-for-husband',
            heading: 'Replacement Landing Page',
        );

        $this->get(DiscoveryUrl::seoLandingPage('old-birthday-gifts-for-husband'))
            ->assertOk()
            ->assertSee('Replacement Landing Page', false)
            ->assertDontSee('Birthday Gifts for Husband', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function publishedLandingPage(
        string $slug,
        string $heading = 'Birthday Gifts for Husband',
        ?Relationship $relationship = null,
        array $attributes = [],
    ): SeoLandingPage {
        return SeoLandingPage::factory()->published()->create(array_merge([
            'name' => $heading,
            'slug' => $slug,
            'heading' => $heading,
            'status' => SeoLandingPageStatus::Published,
            'relationship_id' => ($relationship ?? $this->relationship())->id,
            'is_indexable' => true,
        ], $attributes));
    }

    private function relationship(): Relationship
    {
        return Relationship::query()->firstOrCreate(
            ['slug' => 'husband'],
            [
                'name' => 'Husband',
                'is_active' => true,
            ],
        );
    }
}
