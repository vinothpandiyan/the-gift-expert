<?php

namespace Tests\Feature\Seeders;

use App\Actions\Category\IsAcceptableMerchandisingCategoryAction;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Profession;
use App\Models\RecipientGender;
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
use Database\Seeders\RecipientGenderSeeder;
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
        RecipientGenderSeeder::class,
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
        $this->assertSame(9, RecipientType::query()->count());
        $this->assertSame(3, RecipientGender::query()->count());
        $this->assertSame(17, Interest::query()->count());
        $this->assertSame(8, Profession::query()->count());
        $this->assertSame(9, GiftType::query()->count());
        $this->assertSame(29, Category::query()->count());
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
            RecipientGender::query()->count(),
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
            RecipientGender::query()->count(),
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
        $this->assertTrue(RecipientType::query()->where('slug', 'baby')->value('is_active'));
        $this->assertTrue(RecipientType::query()->where('slug', 'school-student')->value('is_active'));
        $this->assertTrue(RecipientType::query()->where('slug', 'college-student')->value('is_active'));
        $this->assertTrue(RecipientGender::query()->where('slug', 'male')->value('is_active'));
        $this->assertTrue(RecipientGender::query()->where('slug', 'female')->value('is_active'));
        $this->assertTrue(RecipientGender::query()->where('slug', 'unisex')->value('is_active'));

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
        $this->assertTrue(Category::query()->where('slug', 'gift-cards-vouchers')->where('is_active', true)->exists());
        $this->assertTrue(Category::query()->where('slug', 'musical-instruments')->where('is_active', true)->exists());
        $this->assertTrue(Category::query()->where('slug', 'automotive-vehicle-care')->where('is_active', true)->exists());
        $this->assertTrue(Category::query()->where('slug', 'gardening-plant-care')->where('is_active', true)->exists());
        $this->assertTrue(Category::query()->where('slug', 'cameras-photography')->where('is_active', true)->exists());
        $this->assertTrue(Category::query()->where('slug', 'home-decor-keepsakes')->where('is_active', true)->exists());
        $this->assertTrue(Category::query()->where('slug', 'bags-wallets-luggage')->where('is_active', true)->exists());
        $this->assertTrue(Category::query()->where('slug', 'travel-accessories')->where('is_active', true)->exists());
        $this->assertTrue(Category::query()->where('slug', 'footwear')->where('is_active', true)->exists());
        $this->assertTrue(Category::query()->where('slug', 'footwear-accessories')->where('is_active', true)->exists());
        $this->assertTrue(Category::query()->where('slug', 'gift-boxes-hampers')->where('is_active', true)->exists());
        $this->assertSame(
            'fashion-and-accessories/jewellery',
            Category::query()->where('slug', 'jewellery')->value('full_path'),
        );
        $this->assertSame(
            'home-and-living/kitchen-and-dining',
            Category::query()->where('slug', 'kitchen-and-dining')->value('full_path'),
        );
        $this->assertSame(
            'gift-cards-vouchers',
            Category::query()->where('slug', 'gift-cards-vouchers')->value('full_path'),
        );
        $this->assertSame(
            'electronics/musical-instruments',
            Category::query()->where('slug', 'musical-instruments')->value('full_path'),
        );
        $this->assertSame(
            'automotive-vehicle-care',
            Category::query()->where('slug', 'automotive-vehicle-care')->value('full_path'),
        );
        $this->assertSame(
            'gardening-plant-care',
            Category::query()->where('slug', 'gardening-plant-care')->value('full_path'),
        );
        $this->assertSame(
            'electronics/cameras-photography',
            Category::query()->where('slug', 'cameras-photography')->value('full_path'),
        );
        $this->assertSame(
            'home-and-living/home-decor-keepsakes',
            Category::query()->where('slug', 'home-decor-keepsakes')->value('full_path'),
        );
        $this->assertSame(
            'bags-wallets-luggage',
            Category::query()->where('slug', 'bags-wallets-luggage')->value('full_path'),
        );
        $this->assertSame(
            'bags-wallets-luggage/travel-accessories',
            Category::query()->where('slug', 'travel-accessories')->value('full_path'),
        );
        $this->assertSame(
            'footwear',
            Category::query()->where('slug', 'footwear')->value('full_path'),
        );
        $this->assertSame(
            'footwear/footwear-accessories',
            Category::query()->where('slug', 'footwear-accessories')->value('full_path'),
        );
        $this->assertSame(
            'gift-boxes-hampers',
            Category::query()->where('slug', 'gift-boxes-hampers')->value('full_path'),
        );
        $this->assertNull(Category::query()->where('slug', 'gift-cards-vouchers')->value('parent_id'));
        $this->assertNull(Category::query()->where('slug', 'automotive-vehicle-care')->value('parent_id'));
        $this->assertNull(Category::query()->where('slug', 'gardening-plant-care')->value('parent_id'));
        $this->assertNull(Category::query()->where('slug', 'bags-wallets-luggage')->value('parent_id'));
        $this->assertNull(Category::query()->where('slug', 'footwear')->value('parent_id'));
        $this->assertNull(Category::query()->where('slug', 'gift-boxes-hampers')->value('parent_id'));
        $this->assertSame(
            Category::query()->where('slug', 'electronics')->whereNull('parent_id')->value('id'),
            Category::query()->where('slug', 'musical-instruments')->value('parent_id'),
        );
        $this->assertSame(
            Category::query()->where('slug', 'electronics')->whereNull('parent_id')->value('id'),
            Category::query()->where('slug', 'cameras-photography')->value('parent_id'),
        );
        $this->assertSame(
            Category::query()->where('slug', 'home-and-living')->whereNull('parent_id')->value('id'),
            Category::query()->where('slug', 'home-decor-keepsakes')->value('parent_id'),
        );
        $this->assertSame(
            Category::query()->where('slug', 'bags-wallets-luggage')->whereNull('parent_id')->value('id'),
            Category::query()->where('slug', 'travel-accessories')->value('parent_id'),
        );
        $this->assertSame(
            Category::query()->where('slug', 'footwear')->whereNull('parent_id')->value('id'),
            Category::query()->where('slug', 'footwear-accessories')->value('parent_id'),
        );
        $this->assertNull(Category::query()->where('slug', 'stationery-and-office')->value('parent_id'));
        $this->assertNull(Category::query()->where('slug', 'spiritual-and-pooja')->value('parent_id'));
        $this->assertNull(Category::query()->where('slug', 'sports-and-outdoors')->value('parent_id'));
        $isAcceptable = app(IsAcceptableMerchandisingCategoryAction::class);
        foreach ([
            'automotive-vehicle-care',
            'gardening-plant-care',
            'cameras-photography',
            'home-decor-keepsakes',
            'bags-wallets-luggage',
            'travel-accessories',
            'footwear',
            'footwear-accessories',
            'gift-boxes-hampers',
        ] as $slug) {
            $this->assertTrue(
                $isAcceptable->execute((int) Category::query()->where('slug', $slug)->value('id')),
                "Expected {$slug} to be primary-merchandising eligible.",
            );
        }
        $this->assertTrue(GiftType::query()->where('slug', 'hampers-gift-sets')->where('is_active', true)->exists());
        $this->assertNotSame(
            GiftType::query()->where('slug', 'hampers-gift-sets')->value('id'),
            Category::query()->where('slug', 'gift-boxes-hampers')->value('id'),
        );
    }

    public function test_category_seeder_preserves_existing_product_taxonomy_and_classification_metadata(): void
    {
        $this->seed($this->taxonomySeeders);

        $home = Category::query()->where('slug', 'home-and-living')->whereNull('parent_id')->firstOrFail();
        $occasion = Occasion::query()->where('slug', 'birthday')->firstOrFail();
        $relationship = Relationship::query()->where('slug', 'husband')->firstOrFail();
        $recipientType = RecipientType::query()->where('slug', 'adult')->firstOrFail();
        $recipientGender = RecipientGender::query()->where('slug', 'male')->firstOrFail();
        $interest = Interest::query()->where('slug', 'technology')->firstOrFail();
        $profession = Profession::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        $giftType = GiftType::query()->where('slug', 'personalized-gifts')->firstOrFail();

        $product = Product::factory()->create([
            'status' => ProductStatus::Draft,
            'taxonomy_classification_status' => TaxonomyClassificationStatus::Review,
            'taxonomy_classification_version' => 2,
            'taxonomy_review_reasons' => ['low_primary_category_confidence'],
            'taxonomy_classification_proposal' => [
                'primary_category_id' => $home->id,
                'review_reasons' => ['low_primary_category_confidence'],
            ],
            'taxonomy_gap_suggestion' => 'Automotive Care',
            'taxonomy_gap_explanation' => 'Preserved gap explanation.',
            'taxonomy_approved_at' => null,
            'taxonomy_approved_by_user_id' => null,
            'taxonomy_proposal_pending' => false,
        ]);

        $product->categories()->attach($home->id, ['is_primary' => true]);
        $product->occasions()->attach($occasion->id);
        $product->relationships()->attach($relationship->id);
        $product->recipientTypes()->attach($recipientType->id);
        $product->recipientGenders()->attach($recipientGender->id);
        $product->interests()->attach($interest->id);
        $product->professions()->attach($profession->id);
        $product->giftTypes()->attach($giftType->id);

        $snapshot = [
            'status' => $product->status->value,
            'taxonomy_classification_status' => $product->taxonomy_classification_status->value,
            'taxonomy_classification_version' => $product->taxonomy_classification_version,
            'taxonomy_review_reasons' => $product->taxonomy_review_reasons,
            'taxonomy_classification_proposal' => $product->taxonomy_classification_proposal,
            'taxonomy_gap_suggestion' => $product->taxonomy_gap_suggestion,
            'taxonomy_gap_explanation' => $product->taxonomy_gap_explanation,
            'taxonomy_proposal_pending' => $product->taxonomy_proposal_pending,
            'category_ids' => $product->categories()->pluck('categories.id')->sort()->values()->all(),
            'occasion_ids' => $product->occasions()->pluck('occasions.id')->sort()->values()->all(),
            'relationship_ids' => $product->relationships()->pluck('relationships.id')->sort()->values()->all(),
            'recipient_type_ids' => $product->recipientTypes()->pluck('recipient_types.id')->sort()->values()->all(),
            'recipient_gender_ids' => $product->recipientGenders()->pluck('recipient_genders.id')->sort()->values()->all(),
            'interest_ids' => $product->interests()->pluck('interests.id')->sort()->values()->all(),
            'profession_ids' => $product->professions()->pluck('professions.id')->sort()->values()->all(),
            'gift_type_ids' => $product->giftTypes()->pluck('gift_types.id')->sort()->values()->all(),
            'gift_cards_path' => Category::query()->where('slug', 'gift-cards-vouchers')->value('full_path'),
            'instruments_path' => Category::query()->where('slug', 'musical-instruments')->value('full_path'),
            'category_count' => Category::query()->count(),
        ];

        $this->seed([CategorySeeder::class]);
        $this->seed([CategorySeeder::class]);

        $product->refresh();

        $this->assertSame($snapshot['status'], $product->status->value);
        $this->assertSame($snapshot['taxonomy_classification_status'], $product->taxonomy_classification_status->value);
        $this->assertSame($snapshot['taxonomy_classification_version'], $product->taxonomy_classification_version);
        $this->assertSame($snapshot['taxonomy_review_reasons'], $product->taxonomy_review_reasons);
        $this->assertEqualsCanonicalizing(
            $snapshot['taxonomy_classification_proposal'],
            $product->taxonomy_classification_proposal,
        );
        $this->assertSame($snapshot['taxonomy_gap_suggestion'], $product->taxonomy_gap_suggestion);
        $this->assertSame($snapshot['taxonomy_gap_explanation'], $product->taxonomy_gap_explanation);
        $this->assertSame($snapshot['taxonomy_proposal_pending'], $product->taxonomy_proposal_pending);
        $this->assertSame($snapshot['category_ids'], $product->categories()->pluck('categories.id')->sort()->values()->all());
        $this->assertSame($snapshot['occasion_ids'], $product->occasions()->pluck('occasions.id')->sort()->values()->all());
        $this->assertSame($snapshot['relationship_ids'], $product->relationships()->pluck('relationships.id')->sort()->values()->all());
        $this->assertSame($snapshot['recipient_type_ids'], $product->recipientTypes()->pluck('recipient_types.id')->sort()->values()->all());
        $this->assertSame($snapshot['recipient_gender_ids'], $product->recipientGenders()->pluck('recipient_genders.id')->sort()->values()->all());
        $this->assertSame($snapshot['interest_ids'], $product->interests()->pluck('interests.id')->sort()->values()->all());
        $this->assertSame($snapshot['profession_ids'], $product->professions()->pluck('professions.id')->sort()->values()->all());
        $this->assertSame($snapshot['gift_type_ids'], $product->giftTypes()->pluck('gift_types.id')->sort()->values()->all());
        $this->assertSame($snapshot['gift_cards_path'], Category::query()->where('slug', 'gift-cards-vouchers')->value('full_path'));
        $this->assertSame($snapshot['instruments_path'], Category::query()->where('slug', 'musical-instruments')->value('full_path'));
        $this->assertSame($snapshot['category_count'], Category::query()->count());
        $this->assertSame(1, Category::query()->where('slug', 'automotive-vehicle-care')->count());
        $this->assertTrue(Category::query()->where('slug', 'automotive-vehicle-care')->where('is_active', true)->whereNull('parent_id')->exists());
        $this->assertSame(1, Category::query()->where('slug', 'gardening-plant-care')->count());
        $this->assertSame(1, Category::query()->where('slug', 'cameras-photography')->count());
        $this->assertSame(1, Category::query()->where('slug', 'home-decor-keepsakes')->count());
        $this->assertSame(1, Category::query()->where('slug', 'bags-wallets-luggage')->count());
        $this->assertSame(1, Category::query()->where('slug', 'travel-accessories')->count());
        $this->assertSame(1, Category::query()->where('slug', 'footwear')->count());
        $this->assertSame(1, Category::query()->where('slug', 'footwear-accessories')->count());
        $this->assertSame(1, Category::query()->where('slug', 'gift-boxes-hampers')->count());
        $isAcceptable = app(IsAcceptableMerchandisingCategoryAction::class);
        foreach ([
            'automotive-vehicle-care',
            'gardening-plant-care',
            'cameras-photography',
            'home-decor-keepsakes',
            'bags-wallets-luggage',
            'travel-accessories',
            'footwear',
            'footwear-accessories',
            'gift-boxes-hampers',
        ] as $slug) {
            $this->assertTrue(
                $isAcceptable->execute((int) Category::query()->where('slug', $slug)->value('id')),
                "Expected {$slug} to remain primary-merchandising eligible after reseeding.",
            );
        }
    }
}
