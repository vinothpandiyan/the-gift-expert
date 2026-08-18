<?php

namespace Tests\Unit\Support;

use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\DiscoveryUrl;
use App\Support\PageMeta;
use App\Support\Terminology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageMetaGiftBrowseContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_relationship_context_overrides_deterministic_fallback(): void
    {
        [$product, $husband, $brother] = $this->productWithHusbandAndBrother();

        $fallback = PageMeta::giftBreadcrumbs($product);
        $this->assertSame(Terminology::gifts().' for Husband', $fallback[2]['label']);

        $brotherCrumbs = PageMeta::giftBreadcrumbs($product, 'relationship:brother');
        $this->assertSame(Terminology::gifts().' for Brother', $brotherCrumbs[2]['label']);
        $this->assertSame(DiscoveryUrl::relationship($brother->slug), $brotherCrumbs[2]['url']);

        $husbandCrumbs = PageMeta::giftBreadcrumbs($product, 'relationship:husband');
        $this->assertSame(Terminology::gifts().' for Husband', $husbandCrumbs[2]['label']);
        $this->assertSame(DiscoveryUrl::relationship($husband->slug), $husbandCrumbs[2]['url']);
    }

    public function test_forged_unsupported_malformed_and_oversized_context_use_fallback(): void
    {
        [$product] = $this->productWithHusbandAndBrother();
        $fallback = PageMeta::giftBreadcrumbs($product);

        Relationship::query()->create([
            'name' => 'Wife',
            'slug' => 'wife',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->assertSame($fallback, PageMeta::giftBreadcrumbs($product, 'relationship:wife'));
        $this->assertSame($fallback, PageMeta::giftBreadcrumbs($product, 'seo_landing:birthday-gifts-for-husband'));
        $this->assertSame($fallback, PageMeta::giftBreadcrumbs($product, 'budget:under-500'));
        $this->assertSame($fallback, PageMeta::giftBreadcrumbs($product, 'relationship'));
        $this->assertSame($fallback, PageMeta::giftBreadcrumbs($product, 'relationship:'));
        $this->assertSame($fallback, PageMeta::giftBreadcrumbs($product, ':brother'));
        $this->assertSame($fallback, PageMeta::giftBreadcrumbs($product, str_repeat('a', 257)));
        $this->assertSame($fallback, PageMeta::giftBreadcrumbs($product, ['relationship:brother']));
        $this->assertSame($fallback, PageMeta::giftBreadcrumbs($product, null));
    }

    public function test_inactive_attached_relationship_context_is_ignored(): void
    {
        $product = Product::factory()->published()->create(['name' => 'Wallet', 'slug' => 'wallet']);
        $inactive = Relationship::query()->create([
            'name' => 'Brother',
            'slug' => 'brother',
            'sort_order' => 1,
            'is_active' => false,
        ]);
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $product->relationships()->attach([$inactive->id, $husband->id]);

        $crumbs = PageMeta::giftBreadcrumbs($product->fresh(), 'relationship:brother');

        $this->assertSame(Terminology::gifts().' for Husband', $crumbs[2]['label']);
    }

    public function test_recipient_type_gift_type_occasion_interest_and_profession_context(): void
    {
        $product = Product::factory()->published()->create(['name' => 'Wallet', 'slug' => 'wallet']);

        $kids = RecipientType::query()->create(['name' => 'Kids', 'slug' => 'kids', 'sort_order' => 1, 'is_active' => true]);
        $product->recipientTypes()->attach($kids->id);
        $this->assertSame(
            Terminology::gifts().' for Kids',
            PageMeta::giftBreadcrumbs($product->fresh(), 'recipient_type:kids')[2]['label'],
        );

        $giftType = GiftType::query()->create(['name' => 'Return Gifts', 'slug' => 'return-gifts', 'sort_order' => 1, 'is_active' => true]);
        $product->giftTypes()->attach($giftType->id);
        $this->assertSame(
            ['Return Gifts', DiscoveryUrl::giftType('return-gifts')],
            [
                PageMeta::giftBreadcrumbs($product->fresh(), 'gift_type:return-gifts')[2]['label'],
                PageMeta::giftBreadcrumbs($product->fresh(), 'gift_type:return-gifts')[2]['url'],
            ],
        );

        $birthday = Occasion::query()->create(['name' => 'Birthday', 'slug' => 'birthday', 'sort_order' => 1, 'is_active' => true]);
        $product->occasions()->attach($birthday->id);
        $this->assertSame(
            'Birthday '.Terminology::gifts(),
            PageMeta::giftBreadcrumbs($product->fresh(), 'occasion:birthday')[2]['label'],
        );

        $coffee = Interest::query()->create(['name' => 'Coffee', 'slug' => 'coffee', 'sort_order' => 1, 'is_active' => true]);
        $product->interests()->attach($coffee->id);
        $this->assertSame(
            'Coffee '.Terminology::giftIdeas(),
            PageMeta::giftBreadcrumbs($product->fresh(), 'interest:coffee')[2]['label'],
        );

        $teacher = Profession::query()->create(['name' => 'Teacher', 'slug' => 'teacher', 'sort_order' => 1, 'is_active' => true]);
        $product->professions()->attach($teacher->id);
        $this->assertSame(
            Terminology::gifts().' for Teacher',
            PageMeta::giftBreadcrumbs($product->fresh(), 'profession:teacher')[2]['label'],
        );
    }

    public function test_category_context_matches_nested_full_path_not_landing_page(): void
    {
        $product = Product::factory()->published()->create(['name' => 'Watch', 'slug' => 'watch']);
        $parent = Category::query()->create([
            'name' => 'Gifts for Him',
            'slug' => 'gifts-for-him',
            'is_active' => true,
        ]);
        $child = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'Gifts for Husband',
            'slug' => 'gifts-for-husband',
            'is_active' => true,
        ]);
        $page = SeoLandingPage::factory()->published()->create([
            'slug' => 'birthday-gifts-for-husband',
            'heading' => 'Birthday Gifts for Husband',
        ]);
        $child->canonical_seo_landing_page_id = $page->id;
        $child->save();
        $product->categories()->attach($child->id, ['is_primary' => true]);

        $fullPath = $child->fresh()->full_path;
        $crumbs = PageMeta::giftBreadcrumbs($product->fresh(), 'category:'.$fullPath);

        $this->assertSame('Gifts for Husband', $crumbs[2]['label']);
        $this->assertSame(DiscoveryUrl::giftIdeasCategory($fullPath), $crumbs[2]['url']);
        $this->assertNotSame(DiscoveryUrl::seoLandingPage($page->slug), $crumbs[2]['url']);
        $this->assertSame(
            PageMeta::giftBreadcrumbs($product->fresh()),
            PageMeta::giftBreadcrumbs($product->fresh(), 'category:missing-path'),
        );
    }

    public function test_landing_page_product_link_context_matches_18a_parent(): void
    {
        $husband = Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true]);
        $birthday = Occasion::query()->create(['name' => 'Birthday', 'slug' => 'birthday', 'is_active' => true]);
        $page = SeoLandingPage::factory()->create([
            'heading' => 'Birthday Gifts for Husband',
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
        ]);
        $page->load(['relationship', 'recipientType', 'giftType', 'occasion', 'profession', 'category', 'interests']);

        $this->assertSame('relationship:husband', PageMeta::seoLandingPageProductLinkContext($page));

        $giftType = GiftType::query()->create(['name' => 'Return Gifts', 'slug' => 'return-gifts', 'is_active' => true]);
        $giftTypePage = SeoLandingPage::factory()->create([
            'heading' => 'Birthday Return Gifts',
            'gift_type_id' => $giftType->id,
            'occasion_id' => $birthday->id,
        ]);
        $giftTypePage->load(['relationship', 'recipientType', 'giftType', 'occasion', 'profession', 'category', 'interests']);

        $this->assertSame('gift_type:return-gifts', PageMeta::seoLandingPageProductLinkContext($giftTypePage));

        $single = SeoLandingPage::factory()->create([
            'heading' => 'Gifts for Husband',
            'relationship_id' => $husband->id,
        ]);
        $single->load(['relationship', 'recipientType', 'giftType', 'occasion', 'profession', 'category', 'interests']);

        $this->assertNull(PageMeta::seoLandingPageProductLinkContext($single));
    }

    public function test_canonical_never_includes_context(): void
    {
        $product = Product::factory()->published()->create([
            'name' => 'Wallet',
            'slug' => 'wallet',
        ]);

        $this->assertSame(DiscoveryUrl::gift('wallet', absolute: true), PageMeta::giftCanonical($product));
    }

    /**
     * @return array{0: Product, 1: Relationship, 2: Relationship}
     */
    private function productWithHusbandAndBrother(): array
    {
        $product = Product::factory()->published()->create([
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

        return [$product->fresh(), $husband, $brother];
    }
}
