<?php

namespace Tests\Feature\Seo;

use App\Models\Category;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Models\SeoLandingPageRedirect;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoLandingPageSitemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_landing_page_appears_in_the_sitemap(): void
    {
        $page = $this->sitemapPage('birthday-gifts-for-husband');

        $response = $this->get(DiscoveryUrl::sitemap())->assertOk();

        $this->assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));
        $response
            ->assertSee('<loc>'.DiscoveryUrl::seoLandingPage($page->slug, absolute: true).'</loc>', false)
            ->assertSee('<lastmod>'.$page->updated_at->toAtomString().'</lastmod>', false);
    }

    public function test_include_in_sitemap_false_excludes_the_page(): void
    {
        $page = $this->sitemapPage('excluded-by-flag', [
            'include_in_sitemap' => false,
        ]);

        $this->get(DiscoveryUrl::sitemap())
            ->assertOk()
            ->assertDontSee(DiscoveryUrl::seoLandingPage($page->slug, absolute: true), false);
    }

    public function test_noindex_page_is_excluded_even_when_sitemap_flag_is_true(): void
    {
        $page = $this->sitemapPage('noindex-page', [
            'is_indexable' => false,
            'include_in_sitemap' => true,
        ]);

        $this->get(DiscoveryUrl::sitemap())
            ->assertOk()
            ->assertDontSee(DiscoveryUrl::seoLandingPage($page->slug, absolute: true), false);
    }

    public function test_draft_page_is_excluded(): void
    {
        $page = SeoLandingPage::factory()->draft()->create([
            'slug' => 'draft-page',
            'is_indexable' => true,
            'include_in_sitemap' => true,
        ]);

        $this->get(DiscoveryUrl::sitemap())
            ->assertOk()
            ->assertDontSee(DiscoveryUrl::seoLandingPage($page->slug, absolute: true), false);
    }

    public function test_soft_deleted_page_is_excluded(): void
    {
        $page = $this->sitemapPage('deleted-page');
        $page->delete();

        $this->get(DiscoveryUrl::sitemap())
            ->assertOk()
            ->assertDontSee(DiscoveryUrl::seoLandingPage($page->slug, absolute: true), false);
    }

    public function test_multiple_eligible_landing_pages_are_included(): void
    {
        $first = $this->sitemapPage('anniversary-gifts-for-husband');
        $second = $this->sitemapPage('birthday-gifts-for-husband');

        $response = $this->get(DiscoveryUrl::sitemap())->assertOk();

        $response->assertSee(DiscoveryUrl::seoLandingPage($first->slug, absolute: true), false);
        $response->assertSee(DiscoveryUrl::seoLandingPage($second->slug, absolute: true), false);
    }

    public function test_sitemap_uses_discovery_url_not_a_custom_canonical(): void
    {
        $page = $this->sitemapPage('canonical-override-page', [
            'canonical_url' => 'https://cdn.example.test/birthday-gifts-for-husband',
        ]);

        $this->get(DiscoveryUrl::sitemap())
            ->assertOk()
            ->assertSee('<loc>'.DiscoveryUrl::seoLandingPage($page->slug, absolute: true).'</loc>', false)
            ->assertDontSee('https://cdn.example.test/birthday-gifts-for-husband', false);
    }

    public function test_sitemap_does_not_include_taxonomy_category_or_redirect_urls(): void
    {
        $page = $this->sitemapPage('birthday-gifts-for-husband');

        $relationship = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);
        $category = Category::query()->create([
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true,
        ]);
        SeoLandingPageRedirect::query()->create([
            'from_slug' => 'old-birthday-gifts-for-husband',
            'to_slug' => $page->slug,
            'seo_landing_page_id' => $page->id,
        ]);

        $this->get(DiscoveryUrl::sitemap())
            ->assertOk()
            ->assertSee(DiscoveryUrl::seoLandingPage($page->slug, absolute: true), false)
            ->assertDontSee(DiscoveryUrl::relationship($relationship->slug, absolute: true), false)
            ->assertDontSee(DiscoveryUrl::giftIdeasCategory($category->fresh()->full_path, absolute: true), false)
            ->assertDontSee(DiscoveryUrl::seoLandingPage('old-birthday-gifts-for-husband', absolute: true), false)
            ->assertDontSee('?page=', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sitemapPage(string $slug, array $attributes = []): SeoLandingPage
    {
        return SeoLandingPage::factory()->published()->create(array_merge([
            'slug' => $slug,
            'heading' => $slug,
            'is_indexable' => true,
            'include_in_sitemap' => true,
        ], $attributes));
    }
}
