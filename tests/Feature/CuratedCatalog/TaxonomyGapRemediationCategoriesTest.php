<?php

namespace Tests\Feature\CuratedCatalog;

use App\Actions\CatalogCandidate\LoadActiveTaxonomyCatalogAction;
use App\Actions\CatalogCandidate\ValidateProductTaxonomyClassificationAction;
use App\Actions\Category\IsAcceptableMerchandisingCategoryAction;
use App\Actions\CuratedCatalog\ClassifyCuratedMerchantProductAction;
use App\Actions\CuratedCatalog\EnrichCuratedMerchantProductAction;
use App\Actions\CuratedCatalog\LoadGiftTaxonomySelectOptionsAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyClassificationWarningCode;
use App\Models\AffiliateLink;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\RecipientGender;
use Database\Seeders\CategorySeeder;
use Database\Seeders\GiftTypeSeeder;
use Database\Seeders\OccasionSeeder;
use Database\Seeders\RecipientGenderSeeder;
use Database\Seeders\RecipientTypeSeeder;
use Database\Seeders\RelationshipSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\Support\FakesCommercialEnrichment;
use Tests\TestCase;

class TaxonomyGapRemediationCategoriesTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use FakesCommercialEnrichment;
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->merchant = $this->configureCuratedAmazonMerchant();
        $this->seed([
            OccasionSeeder::class,
            RelationshipSeeder::class,
            RecipientTypeSeeder::class,
            RecipientGenderSeeder::class,
            GiftTypeSeeder::class,
            CategorySeeder::class,
        ]);
    }

    public function test_seeded_gap_remediation_categories_are_active_unique_and_primary_eligible(): void
    {
        $expected = [
            'gift-cards-vouchers' => ['parent' => null, 'full_path' => 'gift-cards-vouchers'],
            'musical-instruments' => ['parent' => 'electronics', 'full_path' => 'electronics/musical-instruments'],
            'automotive-vehicle-care' => ['parent' => null, 'full_path' => 'automotive-vehicle-care'],
            'gardening-plant-care' => ['parent' => null, 'full_path' => 'gardening-plant-care'],
            'cameras-photography' => ['parent' => 'electronics', 'full_path' => 'electronics/cameras-photography'],
            'home-decor-keepsakes' => ['parent' => 'home-and-living', 'full_path' => 'home-and-living/home-decor-keepsakes'],
            'bags-wallets-luggage' => ['parent' => null, 'full_path' => 'bags-wallets-luggage'],
            'travel-accessories' => ['parent' => 'bags-wallets-luggage', 'full_path' => 'bags-wallets-luggage/travel-accessories'],
            'footwear' => ['parent' => null, 'full_path' => 'footwear'],
            'footwear-accessories' => ['parent' => 'footwear', 'full_path' => 'footwear/footwear-accessories'],
            'gift-boxes-hampers' => ['parent' => null, 'full_path' => 'gift-boxes-hampers'],
        ];

        $isAcceptable = app(IsAcceptableMerchandisingCategoryAction::class);
        $options = app(LoadGiftTaxonomySelectOptionsAction::class)->execute();
        $catalog = app(LoadActiveTaxonomyCatalogAction::class)->execute();
        $slugs = collect($catalog->categories)->pluck('slug')->all();

        foreach ($expected as $slug => $meta) {
            $rows = Category::query()->where('slug', $slug)->get();
            $this->assertCount(1, $rows, "Expected exactly one Category for {$slug}.");
            $category = $rows->first();
            $this->assertTrue($category->is_active);
            $this->assertSame($meta['full_path'], $category->full_path);
            $this->assertNull($category->canonical_seo_landing_page_id);
            $this->assertTrue($isAcceptable->execute((int) $category->id));
            $this->assertArrayHasKey((int) $category->id, $options['categories']);
            $this->assertContains($slug, $slugs);

            if ($meta['parent'] === null) {
                $this->assertNull($category->parent_id);
            } else {
                $parentId = Category::query()
                    ->where('slug', $meta['parent'])
                    ->when(
                        in_array($meta['parent'], ['electronics', 'home-and-living', 'bags-wallets-luggage', 'footwear'], true),
                        fn ($query) => $query->whereNull('parent_id'),
                    )
                    ->value('id');
                $this->assertSame($parentId, $category->parent_id);
            }
        }

        $this->assertTrue(GiftType::query()->where('slug', 'gift-cards')->where('is_active', true)->exists());
        $this->assertTrue(GiftType::query()->where('slug', 'hampers-gift-sets')->where('is_active', true)->exists());
        $this->assertNotSame(
            GiftType::query()->where('slug', 'gift-cards')->value('id'),
            Category::query()->where('slug', 'gift-cards-vouchers')->value('id'),
        );
        $this->assertNotSame(
            GiftType::query()->where('slug', 'hampers-gift-sets')->value('id'),
            Category::query()->where('slug', 'gift-boxes-hampers')->value('id'),
        );
        $this->assertSame(29, Category::query()->count());
    }

    public function test_digital_gift_card_fixture_accepts_gift_cards_vouchers_primary(): void
    {
        $giftCards = Category::query()->where('slug', 'gift-cards-vouchers')->firstOrFail();
        $cardType = GiftType::query()->where('slug', 'gift-cards')->firstOrFail();
        $digital = GiftType::query()->where('slug', 'digital-instant-gifts')->firstOrFail();
        $unisex = RecipientGender::query()->where('slug', 'unisex')->firstOrFail();
        $product = $this->draftProduct('Amazon Pay Gift Card (Digital)');

        $this->fakeClassificationResponse(
            primaryId: (int) $giftCards->id,
            categoryIds: [(int) $giftCards->id],
            giftTypeIds: [(int) $cardType->id, (int) $digital->id],
            recipientGenderIds: [(int) $unisex->id],
            confidence: 0.97,
        );

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertTrue($result->classified);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertNotContains(
            TaxonomyClassificationWarningCode::MissingPrimaryCategory->value,
            $product->taxonomy_review_reasons ?? [],
        );
        $this->assertTrue((bool) $product->categories()->where('categories.id', $giftCards->id)->first()?->pivot->is_primary);
        $this->assertEqualsCanonicalizing(
            [$cardType->id, $digital->id],
            $product->giftTypes()->pluck('gift_types.id')->all(),
        );
        $this->assertSame([$unisex->id], $product->recipientGenders()->pluck('recipient_genders.id')->all());
        $this->assertSame(ProductStatus::Draft, $product->status);
    }

    public function test_acoustic_guitar_fixture_accepts_musical_instruments_primary(): void
    {
        $instruments = Category::query()->where('slug', 'musical-instruments')->firstOrFail();
        $electronics = Category::query()->where('slug', 'electronics')->whereNull('parent_id')->firstOrFail();
        $product = $this->draftProduct('Juarez Acoustic Guitar Kit, 38 Inch Cutaway, Black');

        $this->fakeClassificationResponse(
            primaryId: (int) $instruments->id,
            categoryIds: [(int) $instruments->id],
            confidence: 0.94,
        );

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertTrue($result->classified);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertNotContains(
            TaxonomyClassificationWarningCode::MissingPrimaryCategory->value,
            $product->taxonomy_review_reasons ?? [],
        );
        $this->assertTrue((bool) $product->categories()->where('categories.id', $instruments->id)->first()?->pivot->is_primary);
        $this->assertTrue($product->categories()->where('categories.id', $electronics->id)->exists());
        $this->assertFalse((bool) $product->categories()->where('categories.id', $electronics->id)->first()?->pivot->is_primary);
        $this->assertSame(ProductStatus::Draft, $product->status);
    }

    public function test_plant_kit_fixture_accepts_gardening_plant_care_primary(): void
    {
        $gardening = Category::query()->where('slug', 'gardening-plant-care')->firstOrFail();
        $product = $this->draftProduct('Indoor Herb Garden Starter Kit with Planters and Soil');

        $this->fakeClassificationResponse(
            primaryId: (int) $gardening->id,
            categoryIds: [(int) $gardening->id],
            confidence: 0.95,
        );

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertTrue($result->classified);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertTrue((bool) $product->categories()->where('categories.id', $gardening->id)->first()?->pivot->is_primary);
        $this->assertSame(ProductStatus::Draft, $product->status);
    }

    public function test_instant_camera_fixture_accepts_cameras_photography_primary(): void
    {
        $cameras = Category::query()->where('slug', 'cameras-photography')->firstOrFail();
        $electronics = Category::query()->where('slug', 'electronics')->whereNull('parent_id')->firstOrFail();
        $product = $this->draftProduct('Fujifilm Instax Mini Instant Camera');

        $this->fakeClassificationResponse(
            primaryId: (int) $cameras->id,
            categoryIds: [(int) $cameras->id],
            confidence: 0.96,
        );

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertTrue($result->classified);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertTrue((bool) $product->categories()->where('categories.id', $cameras->id)->first()?->pivot->is_primary);
        $this->assertTrue($product->categories()->where('categories.id', $electronics->id)->exists());
        $this->assertFalse((bool) $product->categories()->where('categories.id', $electronics->id)->first()?->pivot->is_primary);
        $this->assertSame(ProductStatus::Draft, $product->status);
    }

    public function test_photo_frame_keepsake_fixture_accepts_home_decor_keepsakes_primary(): void
    {
        $decor = Category::query()->where('slug', 'home-decor-keepsakes')->firstOrFail();
        $home = Category::query()->where('slug', 'home-and-living')->whereNull('parent_id')->firstOrFail();
        $product = $this->draftProduct('Personalized Wooden Photo Frame Keepsake Display');

        $this->fakeClassificationResponse(
            primaryId: (int) $decor->id,
            categoryIds: [(int) $decor->id],
            confidence: 0.93,
        );

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertTrue($result->classified);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertTrue((bool) $product->categories()->where('categories.id', $decor->id)->first()?->pivot->is_primary);
        $this->assertTrue($product->categories()->where('categories.id', $home->id)->exists());
        $this->assertFalse((bool) $product->categories()->where('categories.id', $home->id)->first()?->pivot->is_primary);
        $this->assertSame(ProductStatus::Draft, $product->status);
    }

    public function test_leather_wallet_fixture_accepts_bags_wallets_luggage_primary(): void
    {
        $bags = Category::query()->where('slug', 'bags-wallets-luggage')->firstOrFail();
        $product = $this->draftProduct('Genuine Leather Bifold Wallet for Men');

        $this->fakeClassificationResponse(
            primaryId: (int) $bags->id,
            categoryIds: [(int) $bags->id],
            confidence: 0.96,
        );

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertTrue($result->classified);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertTrue((bool) $product->categories()->where('categories.id', $bags->id)->first()?->pivot->is_primary);
        $this->assertSame(ProductStatus::Draft, $product->status);
    }

    public function test_passport_holder_fixture_accepts_travel_accessories_primary(): void
    {
        $travel = Category::query()->where('slug', 'travel-accessories')->firstOrFail();
        $bags = Category::query()->where('slug', 'bags-wallets-luggage')->whereNull('parent_id')->firstOrFail();
        $product = $this->draftProduct('RFID Passport Holder with Luggage Tag Set');

        $this->fakeClassificationResponse(
            primaryId: (int) $travel->id,
            categoryIds: [(int) $travel->id],
            confidence: 0.94,
        );

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertTrue($result->classified);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertTrue((bool) $product->categories()->where('categories.id', $travel->id)->first()?->pivot->is_primary);
        $this->assertTrue($product->categories()->where('categories.id', $bags->id)->exists());
        $this->assertFalse((bool) $product->categories()->where('categories.id', $bags->id)->first()?->pivot->is_primary);
        $this->assertSame(ProductStatus::Draft, $product->status);
    }

    public function test_sneakers_fixture_accepts_footwear_primary(): void
    {
        $footwear = Category::query()->where('slug', 'footwear')->firstOrFail();
        $product = $this->draftProduct('Unisex Casual Sneakers Walking Shoes');

        $this->fakeClassificationResponse(
            primaryId: (int) $footwear->id,
            categoryIds: [(int) $footwear->id],
            confidence: 0.97,
        );

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertTrue($result->classified);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertTrue((bool) $product->categories()->where('categories.id', $footwear->id)->first()?->pivot->is_primary);
        $this->assertSame(ProductStatus::Draft, $product->status);
    }

    public function test_shoe_care_kit_fixture_accepts_footwear_accessories_primary(): void
    {
        $accessories = Category::query()->where('slug', 'footwear-accessories')->firstOrFail();
        $footwear = Category::query()->where('slug', 'footwear')->whereNull('parent_id')->firstOrFail();
        $product = $this->draftProduct('SNEAKARE Quick Shoe Cleaning Kit with Brush');

        $this->fakeClassificationResponse(
            primaryId: (int) $accessories->id,
            categoryIds: [(int) $accessories->id],
            confidence: 0.95,
        );

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertTrue($result->classified);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertTrue((bool) $product->categories()->where('categories.id', $accessories->id)->first()?->pivot->is_primary);
        $this->assertTrue($product->categories()->where('categories.id', $footwear->id)->exists());
        $this->assertFalse((bool) $product->categories()->where('categories.id', $footwear->id)->first()?->pivot->is_primary);
        $this->assertSame(ProductStatus::Draft, $product->status);
    }

    public function test_mixed_gift_hamper_fixture_accepts_gift_boxes_hampers_primary(): void
    {
        $giftBoxes = Category::query()->where('slug', 'gift-boxes-hampers')->firstOrFail();
        $hampers = GiftType::query()->where('slug', 'hampers-gift-sets')->firstOrFail();
        $product = $this->draftProduct('Assorted Celebration Gift Hamper Mixed Surprise Box');

        $this->fakeClassificationResponse(
            primaryId: (int) $giftBoxes->id,
            categoryIds: [(int) $giftBoxes->id],
            giftTypeIds: [(int) $hampers->id],
            confidence: 0.91,
        );

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertTrue($result->classified);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertTrue((bool) $product->categories()->where('categories.id', $giftBoxes->id)->first()?->pivot->is_primary);
        $this->assertSame([$hampers->id], $product->giftTypes()->pluck('gift_types.id')->all());
        $this->assertSame(ProductStatus::Draft, $product->status);
    }

    public function test_chocolate_dominant_hamper_uses_food_category_not_gift_boxes(): void
    {
        $food = Category::query()->where('slug', 'food-and-beverages')->whereNull('parent_id')->firstOrFail();
        $giftBoxes = Category::query()->where('slug', 'gift-boxes-hampers')->firstOrFail();
        $hampers = GiftType::query()->where('slug', 'hampers-gift-sets')->firstOrFail();
        $product = $this->draftProduct('Premium Assorted Chocolate Gift Hamper Box');

        $this->fakeClassificationResponse(
            primaryId: (int) $food->id,
            categoryIds: [(int) $food->id],
            giftTypeIds: [(int) $hampers->id],
            confidence: 0.94,
        );

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertTrue($result->classified);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->taxonomy_classification_status);
        $this->assertTrue((bool) $product->categories()->where('categories.id', $food->id)->first()?->pivot->is_primary);
        $this->assertFalse($product->categories()->where('categories.id', $giftBoxes->id)->exists());
        $this->assertSame([$hampers->id], $product->giftTypes()->pluck('gift_types.id')->all());
        $this->assertSame(ProductStatus::Draft, $product->status);
    }

    public function test_ambiguous_romantic_combo_without_primary_remains_failed(): void
    {
        $product = $this->draftProduct(
            'ME & YOU Romantic Gift For Wife Birthday | Special Combo Birthday Gift | (Multicolor)',
        );

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'name' => 'Me & You Romantic Gift Combo',
                    'taxonomy' => [
                        'primary_category_id' => null,
                        'category_ids' => [],
                    ],
                    'confidence' => [
                        'primary_category' => 0.12,
                    ],
                    'reasoning_summary' => [
                        'primary_category' => 'Combo contents are unspecified.',
                        'relationships' => 'Wife appears in the title.',
                    ],
                    'taxonomy_gap' => [
                        'detected' => true,
                        'severity' => 'blocking',
                        'suggested_concept' => 'General Gift Sets & Occasion Gifts',
                        'explanation' => 'Product identity is too ambiguous for an honest merchandising Category.',
                    ],
                ]),
            ),
        ]);

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product);

        $product->refresh();
        $this->assertSame(TaxonomyClassificationStatus::Failed, $result->status);
        $this->assertContains(
            TaxonomyClassificationWarningCode::MissingPrimaryCategory->value,
            $product->taxonomy_review_reasons ?? [],
        );
        $this->assertContains(
            TaxonomyClassificationWarningCode::TaxonomyGapBlocking->value,
            $product->taxonomy_review_reasons ?? [],
        );
        $this->assertSame(0, $product->categories()->count());
        $this->assertFalse(
            (bool) $product->categories()->where('categories.slug', 'gift-boxes-hampers')->exists(),
        );
        $this->assertSame(ProductStatus::Draft, $product->status);
    }

    public function test_validation_accepts_new_categories_and_preserves_recipient_gender_cap(): void
    {
        $giftCards = Category::query()->where('slug', 'gift-cards-vouchers')->firstOrFail();
        $instruments = Category::query()->where('slug', 'musical-instruments')->firstOrFail();
        $gardening = Category::query()->where('slug', 'gardening-plant-care')->firstOrFail();
        $giftBoxes = Category::query()->where('slug', 'gift-boxes-hampers')->firstOrFail();
        $male = RecipientGender::query()->where('slug', 'male')->firstOrFail();
        $female = RecipientGender::query()->where('slug', 'female')->firstOrFail();

        $validatedGiftCard = app(ValidateProductTaxonomyClassificationAction::class)->execute([
            'primary_category_id' => $giftCards->id,
            'category_ids' => [$giftCards->id],
            'recipient_gender_ids' => [$male->id],
        ]);
        $this->assertSame($giftCards->id, $validatedGiftCard->primaryCategoryId);
        $this->assertSame([$male->id], $validatedGiftCard->recipientGenderIds);
        $this->assertSame([], $validatedGiftCard->rejectedIds);

        $validatedInstrument = app(ValidateProductTaxonomyClassificationAction::class)->execute([
            'primary_category_id' => $instruments->id,
            'category_ids' => [$instruments->id],
            'recipient_gender_ids' => [$male->id, $female->id],
        ]);
        $this->assertSame($instruments->id, $validatedInstrument->primaryCategoryId);
        $this->assertSame([$male->id], $validatedInstrument->recipientGenderIds);
        $this->assertContains($female->id, $validatedInstrument->rejectedIds);

        $validatedGardening = app(ValidateProductTaxonomyClassificationAction::class)->execute([
            'primary_category_id' => $gardening->id,
            'category_ids' => [$gardening->id],
        ]);
        $this->assertSame($gardening->id, $validatedGardening->primaryCategoryId);

        $validatedGiftBoxes = app(ValidateProductTaxonomyClassificationAction::class)->execute([
            'primary_category_id' => $giftBoxes->id,
            'category_ids' => [$giftBoxes->id],
        ]);
        $this->assertSame($giftBoxes->id, $validatedGiftBoxes->primaryCategoryId);
    }

    public function test_human_locked_classification_is_not_overwritten_after_gap_remediation(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->whereNull('parent_id')->firstOrFail();
        $product = $this->draftProduct('Human Locked Gift');
        $product->taxonomy_classification_status = TaxonomyClassificationStatus::HumanApproved;
        $product->save();
        $product->categories()->attach($home->id, ['is_primary' => true]);

        $this->mock(EnrichCuratedMerchantProductAction::class, function ($mock): void {
            $mock->shouldNotReceive('execute');
        });

        $result = app(ClassifyCuratedMerchantProductAction::class)->execute($product->fresh());

        $this->assertFalse($result->classified);
        $this->assertSame('human_locked', $result->reason);
        $this->assertSame(TaxonomyClassificationStatus::HumanApproved, $product->fresh()->taxonomy_classification_status);
        $this->assertTrue($product->fresh()->categories()->where('categories.id', $home->id)->exists());
    }

    /**
     * @param  list<int>  $categoryIds
     * @param  list<int>  $giftTypeIds
     * @param  list<int>  $recipientGenderIds
     */
    private function fakeClassificationResponse(
        int $primaryId,
        array $categoryIds,
        array $giftTypeIds = [],
        array $recipientGenderIds = [],
        float $confidence = 0.95,
    ): void {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $primaryId,
                        'category_ids' => $categoryIds,
                        'gift_type_ids' => $giftTypeIds,
                        'recipient_gender_ids' => $recipientGenderIds,
                    ],
                    'confidence' => [
                        'primary_category' => $confidence,
                        'gift_types' => 0.99,
                        'recipient_genders' => 0.93,
                    ],
                ]),
            ),
        ]);
    }

    private function draftProduct(string $name): Product
    {
        $product = Product::factory()->create([
            'name' => $name,
            'status' => ProductStatus::Draft,
            'taxonomy_classification_status' => TaxonomyClassificationStatus::None,
            'price_amount' => '1299.00',
            'price_currency' => 'INR',
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $this->merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH?tag=test-tag-20',
            'external_product_id' => 'B0ABCDEFGH',
            'is_primary' => true,
            'status' => AffiliateLinkStatus::Active,
        ]);

        return $product->fresh(['affiliateLinks.merchant']);
    }
}
