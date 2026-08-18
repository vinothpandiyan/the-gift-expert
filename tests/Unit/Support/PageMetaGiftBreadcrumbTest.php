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

class PageMetaGiftBreadcrumbTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_parent_uses_gifts_for_label(): void
    {
        $product = $this->product();
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $product->relationships()->attach($husband->id);

        $this->assertSame(
            $this->trail(
                Terminology::gifts().' for Husband',
                DiscoveryUrl::relationship('husband'),
                $product->name,
            ),
            $this->crumbs($product),
        );
    }

    public function test_recipient_type_parent_is_used_when_no_relationship(): void
    {
        $product = $this->product();
        $kids = RecipientType::query()->create([
            'name' => 'Kids',
            'slug' => 'kids',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $product->recipientTypes()->attach($kids->id);

        $this->assertSame(
            $this->trail(
                Terminology::gifts().' for Kids',
                DiscoveryUrl::recipientType('kids'),
                $product->name,
            ),
            $this->crumbs($product),
        );
    }

    public function test_gift_type_parent_uses_record_name_including_return_gifts(): void
    {
        $product = $this->product();
        $giftType = GiftType::query()->create([
            'name' => 'Return Gifts',
            'slug' => 'return-gifts',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $product->giftTypes()->attach($giftType->id);

        $this->assertSame(
            $this->trail(
                'Return Gifts',
                DiscoveryUrl::giftType('return-gifts'),
                $product->name,
            ),
            $this->crumbs($product),
        );
    }

    public function test_occasion_parent_is_used_when_higher_dimensions_are_absent(): void
    {
        $product = $this->product();
        $birthday = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $product->occasions()->attach($birthday->id);

        $this->assertSame(
            $this->trail(
                'Birthday '.Terminology::gifts(),
                DiscoveryUrl::occasion('birthday'),
                $product->name,
            ),
            $this->crumbs($product),
        );
    }

    public function test_interest_parent_is_used_when_higher_dimensions_are_absent(): void
    {
        $product = $this->product();
        $coffee = Interest::query()->create([
            'name' => 'Coffee',
            'slug' => 'coffee',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $product->interests()->attach($coffee->id);

        $this->assertSame(
            $this->trail(
                'Coffee '.Terminology::giftIdeas(),
                DiscoveryUrl::interest('coffee'),
                $product->name,
            ),
            $this->crumbs($product),
        );
    }

    public function test_profession_parent_is_used_when_higher_dimensions_are_absent(): void
    {
        $product = $this->product();
        $teacher = Profession::query()->create([
            'name' => 'Teacher',
            'slug' => 'teacher',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $product->professions()->attach($teacher->id);

        $this->assertSame(
            $this->trail(
                Terminology::gifts().' for Teacher',
                DiscoveryUrl::profession('teacher'),
                $product->name,
            ),
            $this->crumbs($product),
        );
    }

    public function test_category_fallback_uses_a_single_category_not_ancestors(): void
    {
        $product = $this->product();
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

        $crumbs = $this->crumbs($product);
        $labels = array_column($crumbs, 'label');

        $this->assertSame([
            'Home',
            Terminology::giftIdeas(),
            'Leather Goods',
            $product->name,
        ], $labels);
        $this->assertSame(
            DiscoveryUrl::giftIdeasCategory($child->fresh()->full_path),
            $crumbs[2]['url'],
        );
        $this->assertNotContains('Gifts for Him', $labels);
    }

    public function test_relationship_outranks_recipient_type_gift_type_and_category(): void
    {
        $product = $this->product();
        $this->attachLowerDimensions($product);
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $product->relationships()->attach($husband->id);

        $this->assertSame(
            Terminology::gifts().' for Husband',
            $this->crumbs($product)[2]['label'],
        );
        $this->assertSame(
            DiscoveryUrl::relationship('husband'),
            $this->crumbs($product)[2]['url'],
        );
    }

    public function test_recipient_type_outranks_gift_type(): void
    {
        $product = $this->product();
        $kids = RecipientType::query()->create([
            'name' => 'Kids',
            'slug' => 'kids',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $giftType = GiftType::query()->create([
            'name' => 'Return Gifts',
            'slug' => 'return-gifts',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $product->giftTypes()->attach($giftType->id);
        $product->recipientTypes()->attach($kids->id);

        $this->assertSame(
            Terminology::gifts().' for Kids',
            $this->crumbs($product)[2]['label'],
        );
    }

    public function test_gift_type_outranks_occasion(): void
    {
        $product = $this->product();
        $giftType = GiftType::query()->create([
            'name' => 'Return Gifts',
            'slug' => 'return-gifts',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $birthday = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $product->occasions()->attach($birthday->id);
        $product->giftTypes()->attach($giftType->id);

        $this->assertSame('Return Gifts', $this->crumbs($product)[2]['label']);
        $this->assertSame(DiscoveryUrl::giftType('return-gifts'), $this->crumbs($product)[2]['url']);
    }

    public function test_sort_order_wins_over_attach_order(): void
    {
        $product = $this->product();
        $boyfriend = Relationship::query()->create([
            'name' => 'Boyfriend',
            'slug' => 'boyfriend',
            'sort_order' => 20,
            'is_active' => true,
        ]);
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $product->relationships()->attach([$boyfriend->id, $husband->id]);

        $this->assertSame(
            Terminology::gifts().' for Husband',
            $this->crumbs($product)[2]['label'],
        );
    }

    public function test_slug_wins_when_sort_order_ties_regardless_of_id_or_attach_order(): void
    {
        $product = $this->product();
        $zebra = Relationship::query()->create([
            'name' => 'Zebra',
            'slug' => 'zebra',
            'sort_order' => 5,
            'is_active' => true,
        ]);
        $apple = Relationship::query()->create([
            'name' => 'Apple',
            'slug' => 'apple',
            'sort_order' => 5,
            'is_active' => true,
        ]);
        $this->assertTrue($apple->id > $zebra->id);
        $product->relationships()->attach([$zebra->id, $apple->id]);

        $this->assertSame(
            Terminology::gifts().' for Apple',
            $this->crumbs($product)[2]['label'],
        );
    }

    public function test_id_wins_when_sort_order_and_slug_tie(): void
    {
        $product = $this->product();
        $firstParent = Category::query()->create([
            'name' => 'First Parent',
            'slug' => 'first-parent',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $secondParent = Category::query()->create([
            'name' => 'Second Parent',
            'slug' => 'second-parent',
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $earlier = Category::query()->create([
            'parent_id' => $firstParent->id,
            'name' => 'Shared Earlier',
            'slug' => 'shared',
            'sort_order' => 8,
            'is_active' => true,
        ]);
        $later = Category::query()->create([
            'parent_id' => $secondParent->id,
            'name' => 'Shared Later',
            'slug' => 'shared',
            'sort_order' => 8,
            'is_active' => true,
        ]);
        $this->assertTrue($later->id > $earlier->id);
        $product->categories()->attach($later->id, ['is_primary' => false]);
        $product->categories()->attach($earlier->id, ['is_primary' => false]);

        $this->assertSame('Shared Earlier', $this->crumbs($product)[2]['label']);
        $this->assertSame(
            DiscoveryUrl::giftIdeasCategory($earlier->fresh()->full_path),
            $this->crumbs($product)[2]['url'],
        );
    }

    public function test_primary_category_wins_among_multiple_categories(): void
    {
        $product = $this->product();
        $alpha = Category::query()->create([
            'name' => 'Alpha',
            'slug' => 'alpha',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $primary = Category::query()->create([
            'name' => 'Primary Shelf',
            'slug' => 'primary-shelf',
            'sort_order' => 50,
            'is_active' => true,
        ]);
        $product->categories()->attach($alpha->id, ['is_primary' => false]);
        $product->categories()->attach($primary->id, ['is_primary' => true]);

        $this->assertSame('Primary Shelf', $this->crumbs($product)[2]['label']);
        $this->assertSame(
            DiscoveryUrl::giftIdeasCategory($primary->fresh()->full_path),
            $this->crumbs($product)[2]['url'],
        );
    }

    public function test_category_without_primary_uses_editorial_order(): void
    {
        $product = $this->product();
        $later = Category::query()->create([
            'name' => 'Zebra Shelf',
            'slug' => 'zebra-shelf',
            'sort_order' => 20,
            'is_active' => true,
        ]);
        $earlier = Category::query()->create([
            'name' => 'Books',
            'slug' => 'books',
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $product->categories()->attach($later->id, ['is_primary' => false]);
        $product->categories()->attach($earlier->id, ['is_primary' => false]);

        $this->assertSame('Books', $this->crumbs($product)[2]['label']);
    }

    public function test_product_with_no_taxonomy_or_category_falls_back_to_gift_ideas(): void
    {
        $product = $this->product();

        $this->assertSame([
            ['label' => 'Home', 'url' => url('/')],
            ['label' => Terminology::giftIdeas(), 'url' => DiscoveryUrl::giftIdeas()],
            ['label' => $product->name, 'url' => null],
        ], $this->crumbs($product));
    }

    public function test_mapped_category_crumb_keeps_category_url_not_landing_page(): void
    {
        $product = $this->product();
        $page = SeoLandingPage::factory()->published()->create([
            'name' => 'Birthday Gifts for Husband',
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
        $product->categories()->attach($category->id, ['is_primary' => true]);

        $crumb = $this->crumbs($product)[2];
        $categoryUrl = DiscoveryUrl::giftIdeasCategory($category->fresh()->full_path);

        $this->assertSame('Birthday Gifts for Husband', $crumb['label']);
        $this->assertSame($categoryUrl, $crumb['url']);
        $this->assertNotSame(DiscoveryUrl::seoLandingPage($page->slug), $crumb['url']);
        $this->assertSame(
            DiscoveryUrl::gift($product->slug, absolute: true),
            PageMeta::giftCanonical($product),
        );
    }

    public function test_inactive_taxonomy_is_skipped(): void
    {
        $product = $this->product();
        $inactive = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'sort_order' => 1,
            'is_active' => false,
        ]);
        $occasion = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $product->relationships()->attach($inactive->id);
        $product->occasions()->attach($occasion->id);

        $this->assertSame('Birthday '.Terminology::gifts(), $this->crumbs($product)[2]['label']);
    }

    /**
     * @return list<array{label: string, url: ?string}>
     */
    private function crumbs(Product $product): array
    {
        $product->unsetRelation('relationships');
        $product->unsetRelation('recipientTypes');
        $product->unsetRelation('giftTypes');
        $product->unsetRelation('occasions');
        $product->unsetRelation('interests');
        $product->unsetRelation('professions');
        $product->unsetRelation('categories');

        return PageMeta::giftBreadcrumbs($product->fresh());
    }

    /**
     * @return list<array{label: string, url: ?string}>
     */
    private function trail(string $parentLabel, string $parentUrl, string $giftName): array
    {
        return [
            ['label' => 'Home', 'url' => url('/')],
            ['label' => Terminology::giftIdeas(), 'url' => DiscoveryUrl::giftIdeas()],
            ['label' => $parentLabel, 'url' => $parentUrl],
            ['label' => $giftName, 'url' => null],
        ];
    }

    private function product(): Product
    {
        return Product::factory()->published()->create([
            'name' => 'Personalized Leather Wallet',
            'slug' => 'personalized-leather-wallet',
        ]);
    }

    private function attachLowerDimensions(Product $product): void
    {
        $product->recipientTypes()->attach(RecipientType::query()->create([
            'name' => 'Adult',
            'slug' => 'adult',
            'sort_order' => 1,
            'is_active' => true,
        ])->id);
        $product->giftTypes()->attach(GiftType::query()->create([
            'name' => 'Return Gifts',
            'slug' => 'return-gifts',
            'sort_order' => 1,
            'is_active' => true,
        ])->id);
        $product->occasions()->attach(Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'sort_order' => 1,
            'is_active' => true,
        ])->id);
        $product->categories()->attach(Category::query()->create([
            'name' => 'Fashion',
            'slug' => 'fashion',
            'is_active' => true,
        ])->id, ['is_primary' => true]);
    }
}
