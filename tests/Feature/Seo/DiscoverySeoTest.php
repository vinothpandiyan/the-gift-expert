<?php

namespace Tests\Feature\Seo;

use App\Models\Category;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\RecommendationSession;
use App\Models\Relationship;
use App\Support\DiscoveryUrl;
use App\Support\Terminology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Discovery\GiftCatalogTestHelpers;
use Tests\TestCase;

class DiscoverySeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_gift_page_outputs_title_meta_canonical_and_robots(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Wooden Frame',
            'slug' => 'wooden-frame',
            'short_description' => 'A warm personalized frame',
            'meta_title' => null,
            'meta_description' => null,
        ]);

        $canonical = DiscoveryUrl::gift($product->slug, absolute: true);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee('<title>Wooden Frame | '.config('app.name').'</title>', false)
            ->assertSee('<meta name="description" content="A warm personalized frame">', false)
            ->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
            ->assertSee('<meta name="robots" content="index, follow">', false);
    }

    public function test_gift_meta_title_and_canonical_override_are_used(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Override Gift',
            'slug' => 'override-gift',
            'meta_title' => 'Custom Gift Title',
            'meta_description' => 'Custom gift description',
            'canonical_url' => 'https://cdn.example.test/gifts/override-gift',
        ]);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee('<title>Custom Gift Title</title>', false)
            ->assertSee('<meta name="description" content="Custom gift description">', false)
            ->assertSee('<link rel="canonical" href="https://cdn.example.test/gifts/override-gift">', false);
    }

    public function test_gift_breadcrumbs_include_primary_category_path(): void
    {
        $parent = Category::query()->create([
            'name' => 'Gifts for Him',
            'slug' => 'gifts-for-him',
            'is_active' => true,
        ]);
        $child = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);

        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Watch',
            'slug' => 'watch-gift',
        ]);
        $product->categories()->attach($child->id, ['is_primary' => true]);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee(Terminology::giftIdeas(), false)
            ->assertSee('Gifts for Him', false)
            ->assertSee('Husband', false)
            ->assertSee(DiscoveryUrl::giftIdeasCategory($parent->fresh()->full_path), false)
            ->assertSee(DiscoveryUrl::giftIdeasCategory($child->fresh()->full_path), false);
    }

    public function test_unpublished_gift_remains_not_found(): void
    {
        Product::factory()->draft()->create(['slug' => 'seo-draft-gift']);

        $this->get(DiscoveryUrl::gift('seo-draft-gift'))->assertNotFound();
    }

    public function test_category_page_seo_and_pagination_canonicals(): void
    {
        $category = Category::query()->create([
            'name' => 'Personalized',
            'slug' => 'personalized',
            'description' => 'Thoughtful category picks',
            'is_active' => true,
        ]);

        foreach (range(1, 13) as $index) {
            $gift = GiftCatalogTestHelpers::publishedGift([
                'name' => "Gift {$index}",
                'slug' => "seo-gift-{$index}",
            ]);
            $category->products()->attach($gift->id);
        }

        $baseCanonical = DiscoveryUrl::giftIdeasCategory($category->full_path, absolute: true);

        $this->get(DiscoveryUrl::giftIdeasCategory($category->full_path))
            ->assertOk()
            ->assertSee('<title>Personalized '.Terminology::giftIdeas().' | '.config('app.name').'</title>', false)
            ->assertSee('<meta name="description" content="Thoughtful category picks">', false)
            ->assertSee('<link rel="canonical" href="'.$baseCanonical.'">', false)
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('<link rel="next"', false);

        $this->get(DiscoveryUrl::giftIdeasCategory($category->full_path).'?page=2')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.$baseCanonical.'?page=2">', false)
            ->assertSee('<link rel="prev"', false);
    }

    public function test_taxonomy_title_patterns_and_recipient_breadcrumbs(): void
    {
        $occasion = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'description' => 'Birthday listing',
            'is_active' => true,
        ]);

        $this->get(DiscoveryUrl::occasion($occasion->slug))
            ->assertOk()
            ->assertSee('<title>Birthday '.Terminology::gifts().' | '.config('app.name').'</title>', false)
            ->assertSee('<link rel="canonical" href="'.DiscoveryUrl::occasion($occasion->slug, absolute: true).'">', false);

        $relationship = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);

        $this->get(DiscoveryUrl::relationship($relationship->slug))
            ->assertOk()
            ->assertSee('<title>'.Terminology::gifts().' for Husband | '.config('app.name').'</title>', false)
            ->assertSee(Terminology::giftIdeas(), false)
            ->assertSee(Terminology::gifts().' for Husband', false);
    }

    public function test_finder_is_indexable_and_results_are_noindex(): void
    {
        $this->get(DiscoveryUrl::finder())
            ->assertOk()
            ->assertSee('<title>Find a Gift | '.config('app.name').'</title>', false)
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('<link rel="canonical" href="'.DiscoveryUrl::finder(absolute: true).'">', false);

        $session = RecommendationSession::query()->create([]);

        $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->assertSee('<title>'.Terminology::giftRecommendations().' | '.config('app.name').'</title>', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<link rel="canonical" href="'.DiscoveryUrl::finder(absolute: true).'">', false)
            ->assertDontSee(DiscoveryUrl::finderResults($session->uuid, absolute: true), false);
    }

    public function test_affiliate_out_and_products_routes_are_unchanged(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift(['slug' => 'seo-out-gift']);
        $link = $product->affiliateLinks->first();

        $this->assertNotNull($link);

        $this->get(DiscoveryUrl::affiliateOut($link->uuid))
            ->assertRedirect($link->url);

        $productRoutes = collect(Route::getRoutes())
            ->filter(function ($route): bool {
                $uri = '/'.$route->uri();

                return str_starts_with($uri, '/products') || str_contains($uri, '/products/');
            })
            ->values();

        $this->assertCount(0, $productRoutes);
        $this->assertTrue(Route::has('discovery.affiliate.out'));
        $this->assertCount(12, collect(Route::getRoutes())->filter(
            fn ($route) => str_starts_with((string) $route->getName(), 'discovery.'),
        ));
    }
}
