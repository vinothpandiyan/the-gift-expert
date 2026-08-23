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
        $relationships = $this->relationshipIds([
            'husband',
            'wife',
            'boyfriend',
            'girlfriend',
            'brother',
            'sister',
            'son',
            'daughter',
            'friends',
            'colleagues',
        ]);

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
                        'relationship_ids' => $relationships,
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
        $this->assertSame([$electronics->id], $result->taxonomy->categoryIds);
        $this->assertSame($relationships, $result->taxonomy->relationshipIds);
        $this->assertGreaterThan(4, count($result->taxonomy->relationshipIds));
        $this->assertContains(Relationship::query()->where('slug', 'friends')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertContains(Relationship::query()->where('slug', 'colleagues')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertSame([$birthday->id], $result->taxonomy->occasionIds);
        $this->assertSame([], $result->taxonomy->professionIds);
        $this->assertSame([], $result->taxonomy->giftTypeIds);
        $this->assertNotContains('taxonomy_ids_rejected', $result->warnings);
        $this->assertContains('missing_interests', $result->warnings);
        $this->assertContains('missing_recipient_types', $result->warnings);
        $this->assertNotContains('missing_relationships', $result->warnings);
        $this->assertNotContains('missing_occasions', $result->warnings);
    }

    public function test_wallet_combo_preserves_relationships_and_occasions(): void
    {
        $fashion = Category::query()->where('slug', 'fashion-and-accessories')->firstOrFail();
        $birthday = Occasion::query()->where('slug', 'birthday')->firstOrFail();
        $maleRelationships = $this->relationshipIds(['husband', 'boyfriend', 'father', 'brother', 'son']);

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
                        'relationship_ids' => $maleRelationships,
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
        $this->assertSame($maleRelationships, $result->taxonomy->relationshipIds);
        $this->assertNotContains(Relationship::query()->where('slug', 'wife')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertNotContains(Relationship::query()->where('slug', 'mother')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertNotContains(Relationship::query()->where('slug', 'sister')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertNotContains(Relationship::query()->where('slug', 'girlfriend')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertSame([$birthday->id], $result->taxonomy->occasionIds);
    }

    public function test_shoe_cleaning_kit_may_use_fashion_category_and_reports_informational_gaps(): void
    {
        $fashion = Category::query()->where('slug', 'fashion-and-accessories')->firstOrFail();
        $birthday = Occasion::query()->where('slug', 'birthday')->firstOrFail();
        $relationships = $this->relationshipIds([
            'husband',
            'wife',
            'brother',
            'sister',
            'son',
            'daughter',
            'friends',
            'colleagues',
        ]);

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
                        'relationship_ids' => $relationships,
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
        $this->assertSame([$fashion->id], $result->taxonomy->categoryIds);
        $this->assertSame($relationships, $result->taxonomy->relationshipIds);
        $this->assertContains(Relationship::query()->where('slug', 'wife')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertContains(Relationship::query()->where('slug', 'sister')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertContains(Relationship::query()->where('slug', 'friends')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertContains('missing_interests', $result->warnings);
        $this->assertContains('missing_recipient_types', $result->warnings);
        $this->assertNotContains('missing_relationships', $result->warnings);
        $this->assertNotContains('taxonomy_ids_rejected', $result->warnings);
    }

    public function test_generic_alarm_clock_keeps_broad_unisex_eligibility_from_men_curation_group(): void
    {
        $electronics = Category::query()->where('slug', 'electronics')->firstOrFail();
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $relationships = $this->relationshipIds([
            'husband',
            'boyfriend',
            'father',
            'brother',
            'son',
            'wife',
            'girlfriend',
            'mother',
            'sister',
            'daughter',
            'friends',
            'colleagues',
        ]);
        $occasions = $this->occasionIds(['birthday', 'housewarming', 'festival', 'christmas']);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'name' => 'Digital Portable Alarm Clock for Desk',
                    'taxonomy' => [
                        'primary_category_id' => $electronics->id,
                        'category_ids' => [$electronics->id, $home->id],
                        'occasion_ids' => $occasions,
                        'relationship_ids' => $relationships,
                        'recipient_type_ids' => [],
                        'interest_ids' => [],
                        'profession_ids' => [],
                        'gift_type_ids' => [],
                    ],
                ]),
            ),
        ]);

        $result = $this->enrich($this->alarmClockInput('men'));

        $this->assertSame($electronics->id, $result->taxonomy->primaryCategoryId);
        $this->assertSame([$electronics->id, $home->id], $result->taxonomy->categoryIds);
        $this->assertSame($relationships, $result->taxonomy->relationshipIds);
        $this->assertCount(12, $result->taxonomy->relationshipIds);
        $this->assertContains(Relationship::query()->where('slug', 'wife')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertContains(Relationship::query()->where('slug', 'mother')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertContains(Relationship::query()->where('slug', 'colleagues')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertSame($occasions, $result->taxonomy->occasionIds);
        $this->assertNotContains(Occasion::query()->where('slug', 'anniversary')->value('id'), $result->taxonomy->occasionIds);
        $this->assertNotContains(Occasion::query()->where('slug', 'baby-shower')->value('id'), $result->taxonomy->occasionIds);
        $this->assertNotContains('taxonomy_ids_rejected', $result->warnings);
        $this->assertNotContains('taxonomy_too_broad', $result->warnings);
    }

    public function test_relationship_specific_mug_remains_narrow(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();
        $birthday = Occasion::query()->where('slug', 'birthday')->firstOrFail();
        $father = Relationship::query()->where('slug', 'father')->firstOrFail();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'name' => 'Best Dad Ever Personalized Mug',
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                        'occasion_ids' => [$birthday->id],
                        'relationship_ids' => [$father->id],
                        'recipient_type_ids' => [],
                        'interest_ids' => [],
                        'profession_ids' => [],
                        'gift_type_ids' => [],
                    ],
                ]),
            ),
        ]);

        $result = $this->enrich($this->dadMugInput());

        $this->assertSame([$father->id], $result->taxonomy->relationshipIds);
        $this->assertNotContains(Relationship::query()->where('slug', 'husband')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertNotContains(Relationship::query()->where('slug', 'brother')->value('id'), $result->taxonomy->relationshipIds);
        $this->assertNotContains(Relationship::query()->where('slug', 'friends')->value('id'), $result->taxonomy->relationshipIds);
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
            ['Home & Living', 'home-and-living'],
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

        foreach (['Birthday', 'Anniversary', 'Housewarming', 'Baby Shower', 'Festival', 'Christmas'] as $name) {
            Occasion::query()->create([
                'name' => $name,
                'slug' => str($name)->slug()->toString(),
                'is_active' => true,
            ]);
        }

        foreach ([
            'Husband',
            'Boyfriend',
            'Father',
            'Brother',
            'Son',
            'Wife',
            'Girlfriend',
            'Mother',
            'Sister',
            'Daughter',
            'Friends',
            'Parents',
            'Colleagues',
            'Boss',
            'Newlyweds',
            'Grandparents',
        ] as $name) {
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

    private function alarmClockInput(?string $curationGroup): CuratedMerchantProductInput
    {
        return $this->input(
            'B0ALARM001',
            'Digital Portable Alarm Clock for Desk',
            $curationGroup,
        );
    }

    private function dadMugInput(): CuratedMerchantProductInput
    {
        return $this->input(
            'B0DADMUG001',
            'Best Dad Ever Personalized Mug',
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

    /**
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function relationshipIds(array $slugs): array
    {
        return array_map(
            fn (string $slug): int => Relationship::query()->where('slug', $slug)->firstOrFail()->id,
            $slugs,
        );
    }

    /**
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function occasionIds(array $slugs): array
    {
        return array_map(
            fn (string $slug): int => Occasion::query()->where('slug', $slug)->firstOrFail()->id,
            $slugs,
        );
    }
}
