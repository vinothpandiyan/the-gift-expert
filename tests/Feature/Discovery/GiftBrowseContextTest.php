<?php

namespace Tests\Feature\Discovery;

use App\Models\Category;
use App\Models\GiftType;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductSlugRedirect;
use App\Models\RecommendationResult;
use App\Models\RecommendationSession;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use App\Support\Terminology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GiftBrowseContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_listing_cards_include_page_context(): void
    {
        $product = $this->taggedWallet();

        $this->get(DiscoveryUrl::relationship('brother'))
            ->assertOk()
            ->assertSee(DiscoveryUrl::gift($product->slug, context: 'relationship:brother'), false);

        $this->get(DiscoveryUrl::relationship('husband'))
            ->assertOk()
            ->assertSee(DiscoveryUrl::gift($product->slug, context: 'relationship:husband'), false);
    }

    public function test_pdp_uses_valid_context_and_falls_back_without_or_when_forged(): void
    {
        $product = $this->taggedWallet();
        $canonical = DiscoveryUrl::gift($product->slug, absolute: true);

        $this->get(DiscoveryUrl::gift($product->slug, context: 'relationship:brother'))
            ->assertOk()
            ->assertSee(Terminology::gifts().' for Brother', false)
            ->assertSee(DiscoveryUrl::relationship('brother'), false)
            ->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
            ->assertDontSee('<link rel="canonical" href="'.$canonical.'?context=', false);

        $this->get(DiscoveryUrl::gift($product->slug, context: 'relationship:husband'))
            ->assertOk()
            ->assertSee(Terminology::gifts().' for Husband', false);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee(Terminology::gifts().' for Husband', false);

        $this->get(DiscoveryUrl::gift($product->slug, context: 'relationship:wife'))
            ->assertOk()
            ->assertSee(Terminology::gifts().' for Husband', false);
    }

    public function test_malformed_and_array_context_still_render_with_fallback(): void
    {
        $product = $this->taggedWallet();

        $this->get(DiscoveryUrl::gift($product->slug).'?context=relationship')
            ->assertOk()
            ->assertSee(Terminology::gifts().' for Husband', false);

        $this->get(DiscoveryUrl::gift($product->slug).'?context[]=relationship:brother')
            ->assertOk()
            ->assertSee(Terminology::gifts().' for Husband', false);
    }

    public function test_category_listing_encodes_nested_full_path_context(): void
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Watch',
            'slug' => 'watch-gift',
        ]);
        $parent = Category::query()->create([
            'name' => 'Gifts for Him',
            'slug' => 'gifts-for-him',
            'is_active' => true,
        ]);
        $child = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Leather Goods',
            'slug' => 'leather-goods',
            'is_active' => true,
        ]);
        $product->categories()->attach($child->id, ['is_primary' => true]);

        $fullPath = $child->fresh()->full_path;
        $context = 'category:'.$fullPath;

        $this->get(DiscoveryUrl::giftIdeasCategory($fullPath))
            ->assertOk()
            ->assertSee(DiscoveryUrl::gift($product->slug, context: $context), false);

        $this->get(DiscoveryUrl::gift($product->slug, context: $context))
            ->assertOk()
            ->assertSee('Leather Goods', false)
            ->assertSee(DiscoveryUrl::giftIdeasCategory($fullPath), false);
    }

    public function test_mapped_category_context_keeps_category_url_not_landing_page(): void
    {
        $page = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-husband',
            'heading' => 'Birthday Gifts for Husband',
            'is_indexable' => true,
        ]);
        $category = Category::query()->create([
            'name' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'is_active' => true,
            'canonical_seo_landing_page_id' => $page->id,
        ]);
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Mapped Gift',
            'slug' => 'mapped-gift',
        ]);
        $product->categories()->attach($category->id, ['is_primary' => true]);

        $context = 'category:'.$category->fresh()->full_path;
        $categoryUrl = DiscoveryUrl::giftIdeasCategory($category->fresh()->full_path);

        $this->get(DiscoveryUrl::gift($product->slug, context: $context))
            ->assertOk()
            ->assertSee($categoryUrl, false)
            ->assertDontSee('href="'.DiscoveryUrl::seoLandingPage($page->slug).'"', false);
    }

    public function test_seo_landing_page_cards_use_18a_parent_context_not_lp_slug(): void
    {
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);
        $birthday = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);
        $page = SeoLandingPage::factory()->published()->create([
            'name' => 'Birthday Gifts for Husband',
            'slug' => 'birthday-gifts-for-husband',
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
            'is_indexable' => true,
        ]);
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Landing Gift',
            'slug' => 'landing-gift',
        ]);
        $product->relationships()->attach($husband->id);
        $product->occasions()->attach($birthday->id);

        $this->get(DiscoveryUrl::seoLandingPage($page->slug))
            ->assertOk()
            ->assertSee(DiscoveryUrl::gift($product->slug, context: 'relationship:husband'), false)
            ->assertDontSee('context=seo_landing:', false);

        $single = SeoLandingPage::factory()->published()->create([
            'name' => 'Gifts for Husband',
            'slug' => 'gifts-for-husband-page',
            'heading' => 'Gifts for Husband',
            'relationship_id' => $husband->id,
            'is_indexable' => true,
        ]);

        $this->get(DiscoveryUrl::seoLandingPage($single->slug))
            ->assertOk()
            ->assertSee(DiscoveryUrl::gift($product->slug), false)
            ->assertDontSee(DiscoveryUrl::gift($product->slug, context: 'relationship:husband'), false);
    }

    public function test_gift_type_listing_uses_generic_gift_type_context(): void
    {
        $giftType = GiftType::query()->create([
            'name' => 'Return Gifts',
            'slug' => 'return-gifts',
            'is_active' => true,
        ]);
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Return Box',
            'slug' => 'return-box',
        ]);
        $product->giftTypes()->attach($giftType->id);

        $this->get(DiscoveryUrl::giftType('return-gifts'))
            ->assertOk()
            ->assertSee(DiscoveryUrl::gift($product->slug, context: 'gift_type:return-gifts'), false);
    }

    public function test_finder_results_omit_context(): void
    {
        $session = RecommendationSession::query()->create([]);
        $product = $this->taggedWallet();

        RecommendationResult::query()->create([
            'recommendation_session_id' => $session->id,
            'product_id' => $product->id,
            'rank' => 1,
            'score' => 40,
            'score_breakdown' => [],
            'explanation' => 'Matches.',
        ]);

        $this->get(DiscoveryUrl::finderResults($session->uuid))
            ->assertOk()
            ->assertSee(DiscoveryUrl::gift($product->slug), false)
            ->assertDontSee('context=', false);
    }

    public function test_slug_redirect_preserves_only_context(): void
    {
        $product = Product::factory()->published()->create([
            'slug' => 'new-wallet',
        ]);
        ProductSlugRedirect::query()->create([
            'from_slug' => 'old-wallet',
            'to_slug' => 'new-wallet',
            'product_id' => $product->id,
        ]);

        $this->get(DiscoveryUrl::gift('old-wallet', context: 'relationship:brother').'&utm=keep-me')
            ->assertStatus(301)
            ->assertRedirect(DiscoveryUrl::gift('new-wallet', context: 'relationship:brother'));
    }

    public function test_draft_product_is_not_found_with_context(): void
    {
        Product::factory()->draft()->create(['slug' => 'draft-wallet']);

        $this->get(DiscoveryUrl::gift('draft-wallet', context: 'relationship:brother'))
            ->assertNotFound();
    }

    private function taggedWallet(): Product
    {
        $product = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Personalized Leather Wallet',
            'slug' => 'wallet',
        ]);
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $brother = Relationship::query()->create([
            'name' => 'Brother',
            'slug' => 'brother',
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $product->relationships()->attach([$husband->id, $brother->id]);

        return $product;
    }
}
