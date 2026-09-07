<?php

namespace Tests\Feature\Seeders;

use App\Enums\ProductStatus;
use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use Database\Seeders\BudgetRangeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\GiftTypeSeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\MerchantSeeder;
use Database\Seeders\OccasionSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\ProfessionSeeder;
use Database\Seeders\RecipientTypeSeeder;
use Database\Seeders\RelationshipSeeder;
use Database\Seeders\SeoLandingPageSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxonomySeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, class-string<Seeder>>
     */
    private array $taxonomySeeders = [
        BudgetRangeSeeder::class,
        OccasionSeeder::class,
        RelationshipSeeder::class,
        RecipientTypeSeeder::class,
        InterestSeeder::class,
        ProfessionSeeder::class,
        GiftTypeSeeder::class,
        CategorySeeder::class,
    ];

    public function test_taxonomy_seeders_run_without_error(): void
    {
        $this->seed($this->taxonomySeeders);

        $this->assertSame(6, BudgetRange::query()->count());
        $this->assertSame(26, Occasion::query()->count());
        $this->assertSame(16, Relationship::query()->count());
        $this->assertSame(6, RecipientType::query()->count());
        $this->assertSame(17, Interest::query()->count());
        $this->assertSame(8, Profession::query()->count());
        $this->assertSame(9, GiftType::query()->count());
        $this->assertSame(18, Category::query()->count());
        $this->assertSame(
            'gifts-for-him/gifts-for-husband',
            Category::query()->where('slug', 'gifts-for-husband')->value('full_path'),
        );
    }

    public function test_taxonomy_seeders_are_idempotent(): void
    {
        $this->seed($this->taxonomySeeders);

        $counts = [
            BudgetRange::query()->count(),
            Occasion::query()->count(),
            Relationship::query()->count(),
            RecipientType::query()->count(),
            Interest::query()->count(),
            Profession::query()->count(),
            GiftType::query()->count(),
            Category::query()->count(),
        ];

        $this->seed($this->taxonomySeeders);

        $this->assertSame($counts, [
            BudgetRange::query()->count(),
            Occasion::query()->count(),
            Relationship::query()->count(),
            RecipientType::query()->count(),
            Interest::query()->count(),
            Profession::query()->count(),
            GiftType::query()->count(),
            Category::query()->count(),
        ]);
    }

    public function test_database_seeder_creates_development_products_without_publication_violations(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Product::query()->where('slug', 'draft-gift-idea')->where('status', ProductStatus::Draft)->count());
        $this->assertSame(1, Product::query()->where('slug', 'personalized-wooden-photo-frame')->where('status', ProductStatus::Published)->count());
        $this->assertSame(10, Product::query()->published()->count());
    }

    public function test_product_seeder_is_idempotent(): void
    {
        $this->seed([
            MerchantSeeder::class,
            ...$this->taxonomySeeders,
            ProductSeeder::class,
        ]);

        $productCount = Product::query()->count();

        $this->seed([
            MerchantSeeder::class,
            ...$this->taxonomySeeders,
            ProductSeeder::class,
        ]);

        $this->assertSame($productCount, Product::query()->count());
        $this->assertSame(10, Product::query()->published()->count());
    }

    public function test_database_seeder_creates_composite_landing_page_without_copying_category_products(): void
    {
        $this->seed(DatabaseSeeder::class);

        $page = SeoLandingPage::query()->where('slug', 'birthday-gifts-for-husband')->first();
        $this->assertNotNull($page);
        $this->assertSame('published', $page->status->value);
        $this->assertTrue($page->is_indexable);
        $this->assertTrue($page->include_in_sitemap);
        $this->assertNull($page->category_id);
        $this->assertNull($page->recipient_type_id);
        $this->assertSame(
            Relationship::query()->where('slug', 'husband')->value('id'),
            $page->relationship_id,
        );
        $this->assertSame(
            Occasion::query()->where('slug', 'birthday')->value('id'),
            $page->occasion_id,
        );
        $this->assertSame(0, $page->interests()->count());

        $category = Category::query()
            ->where('slug', 'birthday-gifts-for-husband')
            ->where('canonical_seo_landing_page_id', $page->id)
            ->first();

        $this->assertNotNull($category);
        $this->assertFalse($category->is_active);
        $this->assertSame(0, $category->products()->count());
        $this->assertSame(
            1,
            Product::query()->where('slug', 'personalized-wooden-photo-frame')->first()?->categories()->count(),
        );
    }

    public function test_seo_landing_page_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pageCount = SeoLandingPage::query()->count();
        $pivotCount = Category::query()
            ->where('slug', 'birthday-gifts-for-husband')
            ->first()
            ?->products()
            ->count();

        $this->seed([
            OccasionSeeder::class,
            RelationshipSeeder::class,
            CategorySeeder::class,
            SeoLandingPageSeeder::class,
        ]);

        $this->assertSame($pageCount, SeoLandingPage::query()->count());
        $this->assertSame(
            $pivotCount,
            Category::query()->where('slug', 'birthday-gifts-for-husband')->first()?->products()->count(),
        );
    }

    public function test_seeded_composite_category_redirects_while_taxonomy_urls_stay(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get('/gift-ideas/birthday-gifts/birthday-gifts-for-husband')
            ->assertStatus(301)
            ->assertRedirect('/birthday-gifts-for-husband');

        $this->get('/birthday-gifts-for-husband')
            ->assertOk()
            ->assertSee('Birthday Gifts for Husband', false)
            ->assertSee('Personalized Wooden Photo Frame', false);

        $this->get('/gifts-for/husband')
            ->assertOk()
            ->assertSee('Husband', false);

        $this->get('/occasions/birthday')
            ->assertOk()
            ->assertSee('Birthday', false);

        $this->get('/gift-ideas/birthday-gifts')
            ->assertStatus(301)
            ->assertRedirect('/occasions/birthday');
    }

    public function test_seeded_taxonomy_active_inactive_and_renames(): void
    {
        $this->seed($this->taxonomySeeders);

        $this->assertSame(['festival'], Occasion::query()->where('is_active', false)->orderBy('slug')->pluck('slug')->all());
        $this->assertTrue(Occasion::query()->where('slug', 'holi')->where('is_active', true)->exists());
        $this->assertTrue(Occasion::query()->where('slug', 'valentines-day')->where('is_active', true)->exists());
        $this->assertTrue(Occasion::query()->where('slug', 'just-because')->where('is_active', true)->exists());

        $this->assertFalse(RecipientType::query()->where('slug', 'adult')->value('is_active'));
        $this->assertTrue(RecipientType::query()->where('slug', 'kids')->value('is_active'));

        $this->assertSame('Home Chef / Foodie', Interest::query()->where('slug', 'food')->value('name'));
        $this->assertSame('Tech & Gadgets', Interest::query()->where('slug', 'technology')->value('name'));
        $this->assertSame('Pet Parent', Interest::query()->where('slug', 'pets')->value('name'));
        $this->assertTrue(Interest::query()->where('slug', 'gaming')->where('is_active', true)->exists());

        $this->assertTrue(GiftType::query()->where('slug', 'personalized-gifts')->where('is_active', true)->exists());
        $this->assertTrue(GiftType::query()->where('slug', 'hampers-gift-sets')->where('is_active', true)->exists());
        $this->assertTrue(GiftType::query()->where('slug', 'experience-gifts')->where('is_active', true)->exists());
        $this->assertFalse(GiftType::query()->where('slug', 'online-courses')->value('is_active'));
        $this->assertFalse(GiftType::query()->where('slug', 'ebooks-audiobooks')->value('is_active'));

        $inactiveCategories = Category::query()->where('is_active', false)->orderBy('slug')->pluck('slug')->all();
        $this->assertSame([
            'birthday-gifts',
            'birthday-gifts-for-husband',
            'gifts-for-him',
            'gifts-for-husband',
            'personalized-gifts',
        ], $inactiveCategories);

        $this->assertTrue(Category::query()->where('slug', 'jewellery')->where('is_active', true)->exists());
        $this->assertTrue(Category::query()->where('slug', 'kitchen-and-dining')->where('is_active', true)->exists());
        $this->assertSame(
            'fashion-and-accessories/jewellery',
            Category::query()->where('slug', 'jewellery')->value('full_path'),
        );
        $this->assertSame(
            'home-and-living/kitchen-and-dining',
            Category::query()->where('slug', 'kitchen-and-dining')->value('full_path'),
        );
        $this->assertNull(Category::query()->where('slug', 'stationery-and-office')->value('parent_id'));
        $this->assertNull(Category::query()->where('slug', 'spiritual-and-pooja')->value('parent_id'));
        $this->assertNull(Category::query()->where('slug', 'sports-and-outdoors')->value('parent_id'));
    }
}
