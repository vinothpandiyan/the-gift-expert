<?php

namespace Tests\Feature\CuratedCatalog;

use App\Actions\Category\RebuildCategoryPathsAction;
use App\Actions\CuratedCatalog\EnrichCuratedMerchantProductAction;
use App\CommercialSourcing\CommercialEnrichmentException;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Occasion;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\Support\FakesCommercialEnrichment;
use Tests\TestCase;

class EnrichCuratedMerchantProductActionTest extends TestCase
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
        $this->seedTaxonomies();
    }

    public function test_laptop_sleeve_enrichment_preserves_merchandising_taxonomy_and_copy_fields(): void
    {
        $electronics = Category::query()->where('slug', 'electronics')->firstOrFail();
        $birthday = Occasion::query()->where('slug', 'birthday')->firstOrFail();
        $friends = Relationship::query()->where('slug', 'friends')->firstOrFail();
        $colleagues = Relationship::query()->where('slug', 'colleagues')->firstOrFail();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'name' => 'Water-Resistant Laptop Sleeve for 15–15.6 Inch Laptops',
                    'short_description' => 'A padded laptop sleeve with handle for 15 to 15.6 inch laptops and notebooks.',
                    'description' => 'A practical gift for students or commuters who want simple protection for a laptop on the go.',
                    'brand' => null,
                    'taxonomy' => [
                        'primary_category_id' => $electronics->id,
                        'category_ids' => [$electronics->id],
                        'occasion_ids' => [$birthday->id],
                        'relationship_ids' => [$friends->id, $colleagues->id],
                        'recipient_type_ids' => [],
                        'interest_ids' => [],
                        'profession_ids' => [],
                        'gift_type_ids' => [],
                    ],
                ]),
            ),
        ]);

        $result = $this->enrich($this->laptopSleeveInput());

        $this->assertSame('Water-Resistant Laptop Sleeve for 15–15.6 Inch Laptops', $result->name);
        $this->assertStringContainsString('laptop sleeve', strtolower((string) $result->shortDescription));
        $this->assertStringContainsString('gift', strtolower((string) $result->description));
        $this->assertSame($electronics->id, $result->taxonomy->primaryCategoryId);
        $this->assertSame([$friends->id, $colleagues->id], $result->taxonomy->relationshipIds);
        $this->assertSame([$birthday->id], $result->taxonomy->occasionIds);
        $this->assertSame([], $result->taxonomy->professionIds);
        $this->assertSame([], $result->taxonomy->giftTypeIds);
        $this->assertContains('missing_interests', $result->warnings);
        $this->assertContains('missing_recipient_types', $result->warnings);
        $this->assertNotContains('missing_relationships', $result->warnings);
        $this->assertNotContains('missing_occasions', $result->warnings);
    }

    public function test_wallet_combo_preserves_relationships_and_occasions(): void
    {
        $fashion = Category::query()->where('slug', 'fashion-and-accessories')->firstOrFail();
        $birthday = Occasion::query()->where('slug', 'birthday')->firstOrFail();
        $husband = Relationship::query()->where('slug', 'husband')->firstOrFail();
        $father = Relationship::query()->where('slug', 'father')->firstOrFail();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'name' => 'WildHorn Maroon Leather Wallet & Belt Combo Set',
                    'short_description' => 'A maroon leather wallet and belt combo set from WildHorn.',
                    'description' => 'A classic accessories gift for men who appreciate a coordinated wallet and belt set.',
                    'brand' => 'WildHorn',
                    'taxonomy' => [
                        'primary_category_id' => $fashion->id,
                        'category_ids' => [$fashion->id],
                        'occasion_ids' => [$birthday->id],
                        'relationship_ids' => [$husband->id, $father->id],
                        'recipient_type_ids' => [],
                        'interest_ids' => [],
                        'profession_ids' => [],
                        'gift_type_ids' => [],
                    ],
                ]),
            ),
        ]);

        $result = $this->enrich($this->walletComboInput('men'));

        $this->assertSame('WildHorn Maroon Leather Wallet & Belt Combo Set', $result->name);
        $this->assertSame([$husband->id, $father->id], $result->taxonomy->relationshipIds);
        $this->assertSame([$birthday->id], $result->taxonomy->occasionIds);
    }

    public function test_shoe_cleaning_kit_may_use_fashion_category_and_reports_informational_gaps(): void
    {
        $fashion = Category::query()->where('slug', 'fashion-and-accessories')->firstOrFail();
        $birthday = Occasion::query()->where('slug', 'birthday')->firstOrFail();
        $friends = Relationship::query()->where('slug', 'friends')->firstOrFail();
        $brother = Relationship::query()->where('slug', 'brother')->firstOrFail();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'name' => 'SNEAKARE Shoe Cleaning Kit',
                    'short_description' => 'A compact sneaker and shoe-cleaning kit with cleaner and brush for routine footwear care.',
                    'description' => 'A practical gift for sneaker lovers or anyone who likes keeping footwear looking fresh.',
                    'brand' => 'SNEAKARE',
                    'taxonomy' => [
                        'primary_category_id' => $fashion->id,
                        'category_ids' => [$fashion->id],
                        'occasion_ids' => [$birthday->id],
                        'relationship_ids' => [$friends->id, $brother->id],
                        'recipient_type_ids' => [],
                        'interest_ids' => [],
                        'profession_ids' => [],
                        'gift_type_ids' => [],
                    ],
                ]),
            ),
        ]);

        $result = $this->enrich($this->shoeCleaningKitInput('men'));

        $this->assertSame($fashion->id, $result->taxonomy->primaryCategoryId);
        $this->assertContains('missing_interests', $result->warnings);
        $this->assertContains('missing_recipient_types', $result->warnings);
        $this->assertNotContains('missing_relationships', $result->warnings);
    }

    public function test_missing_merchandising_dimensions_add_warning_codes_without_blocking(): void
    {
        $electronics = Category::query()->where('slug', 'electronics')->firstOrFail();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $electronics->id,
                        'category_ids' => [$electronics->id],
                        'occasion_ids' => [],
                        'relationship_ids' => [],
                        'recipient_type_ids' => [],
                        'interest_ids' => [],
                        'profession_ids' => [],
                        'gift_type_ids' => [],
                    ],
                ]),
            ),
        ]);

        $result = $this->enrich($this->laptopSleeveInput());

        $this->assertContains('missing_relationships', $result->warnings);
        $this->assertContains('missing_occasions', $result->warnings);
        $this->assertContains('missing_interests', $result->warnings);
        $this->assertContains('missing_recipient_types', $result->warnings);
    }

    public function test_missing_primary_category_still_fails(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => null,
                        'category_ids' => [],
                    ],
                ]),
            ),
        ]);

        $this->expectException(CommercialEnrichmentException::class);
        $this->expectExceptionMessage('valid primary category');

        $this->enrich($this->laptopSleeveInput());
    }

    private function enrich(CuratedMerchantProductInput $input)
    {
        return app(EnrichCuratedMerchantProductAction::class)->execute($this->merchant, $input);
    }

    private function seedTaxonomies(): void
    {
        foreach ([
            ['Electronics', 'electronics'],
            ['Fashion & Accessories', 'fashion-and-accessories'],
        ] as [$name, $slug]) {
            Category::query()->create([
                'name' => $name,
                'slug' => $slug,
                'is_active' => true,
            ]);
        }

        Category::query()->whereNull('parent_id')->each(
            fn (Category $category) => app(RebuildCategoryPathsAction::class)->execute($category),
        );

        foreach (['Birthday'] as $name) {
            Occasion::query()->create([
                'name' => $name,
                'slug' => str($name)->slug()->toString(),
                'is_active' => true,
            ]);
        }

        foreach (['Friends', 'Colleagues', 'Husband', 'Father', 'Brother'] as $name) {
            Relationship::query()->create([
                'name' => $name,
                'slug' => str($name)->slug()->toString(),
                'is_active' => true,
            ]);
        }
    }

    private function laptopSleeveInput(): CuratedMerchantProductInput
    {
        return $this->input(
            'B0LAPTOP001',
            'Water Resistant Laptop Sleeve/Laptop case/laptop cover with Handle Compatible for 15 Inch to 15.6 Inches laptops & Notebooks - Greys',
        );
    }

    private function walletComboInput(?string $curationGroup): CuratedMerchantProductInput
    {
        return $this->input(
            'B0WALLET001',
            'WildHorn Maroon Leather Men\'s Wallet & Belt Combo Set (699711)',
            $curationGroup,
        );
    }

    private function shoeCleaningKitInput(?string $curationGroup): CuratedMerchantProductInput
    {
        return $this->input(
            'B0SHOECLN01',
            'SNEAKARE Quick Shoe Cleaning Kit Shoe Cleaner & Medium Bristle Shoe Brush, White Shoe Cleaning Kit, Shoe Cleaner Kit For Sneaker,Sports Shoe Cleaner Foam Spray',
            $curationGroup,
        );
    }

    private function input(string $asin, string $title, ?string $curationGroup = null): CuratedMerchantProductInput
    {
        return new CuratedMerchantProductInput(
            itemIndex: 1,
            merchantSlug: 'amazon-in',
            externalProductId: $asin,
            sourceUrl: 'https://www.amazon.in/dp/'.$asin,
            title: $title,
            priceAmount: '999.00',
            priceCurrency: 'INR',
            sourceImageUrl: 'https://m.media-amazon.com/images/I/example.jpg',
            availability: 'in_stock',
            capturedAt: '2026-08-23T13:00:00+05:30',
            curationGroup: $curationGroup,
            sourcePayload: [],
        );
    }
}
