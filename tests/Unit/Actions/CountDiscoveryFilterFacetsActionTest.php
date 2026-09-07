<?php

namespace Tests\Unit\Actions;

use App\Actions\Discovery\CountDiscoveryFilterFacetsAction;
use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
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
use Database\Seeders\BudgetRangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CountDiscoveryFilterFacetsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_husband_page_occasion_counts_ignore_wife_products(): void
    {
        $husband = $this->relationship('Husband');
        $wife = $this->relationship('Wife');
        $birthday = $this->occasion('Birthday');
        $anniversary = $this->occasion('Anniversary');
        $tech = $this->interest('Tech & Gadgets', 'technology');

        $this->gift('a', [$husband], [$birthday], [$tech]);
        $this->gift('b', [$husband], [$anniversary], [$tech]);
        $this->gift('c', [$wife], [$birthday], [$tech]);

        $counts = $this->countFacets(
            DiscoveryListingContext::forRelationship($husband),
            new DiscoveryListingQueryState,
            'occasion',
            [$birthday->id, $anniversary->id],
        );

        $this->assertSame(1, $counts[$birthday->id] ?? 0);
        $this->assertSame(1, $counts[$anniversary->id] ?? 0);
    }

    public function test_occasion_facets_are_disjunctive_when_an_occasion_is_selected(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $anniversary = $this->occasion('Anniversary');
        $tech = $this->interest('Tech & Gadgets', 'technology');

        $this->gift('birthday-tech', [$husband], [$birthday], [$tech]);
        $this->gift('anniversary-tech', [$husband], [$anniversary], [$tech]);

        $counts = $this->countFacets(
            DiscoveryListingContext::forRelationship($husband),
            new DiscoveryListingQueryState(
                occasionSlugs: ['birthday'],
                interestSlugs: ['technology'],
            ),
            'occasion',
            [$birthday->id, $anniversary->id],
        );

        $this->assertSame(1, $counts[$birthday->id] ?? 0);
        $this->assertSame(1, $counts[$anniversary->id] ?? 0);
    }

    public function test_cross_dimension_filters_narrow_occasion_counts(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $anniversary = $this->occasion('Anniversary');
        $tech = $this->interest('Tech & Gadgets', 'technology');
        $personalized = $this->giftType('Personalized Gifts', 'personalized-gifts');

        $matching = $this->gift('personalized-birthday', [$husband], [$birthday], [$tech]);
        $matching->giftTypes()->attach($personalized);

        $this->gift('anniversary-tech', [$husband], [$anniversary], [$tech]);

        $counts = $this->countFacets(
            DiscoveryListingContext::forRelationship($husband),
            new DiscoveryListingQueryState(
                interestSlugs: ['technology'],
                giftTypeSlugs: ['personalized-gifts'],
            ),
            'occasion',
            [$birthday->id, $anniversary->id],
        );

        $this->assertSame(1, $counts[$birthday->id] ?? 0);
        $this->assertSame(0, $counts[$anniversary->id] ?? 0);
    }

    public function test_or_within_dimension_and_and_across_dimensions(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $anniversary = $this->occasion('Anniversary');
        $tech = $this->interest('Tech & Gadgets', 'technology');

        $birthdayGift = $this->gift('birthday', [$husband], [$birthday], [$tech]);
        $anniversaryGift = $this->gift('anniversary', [$husband], [$anniversary], [$tech]);
        $this->gift('husband-only', [$husband], [], [$tech]);

        $listingIds = app(QueryPublishedProductsByFiltersAction::class)
            ->execute([
                'relationship_id' => $husband->id,
                'occasion_ids' => [$birthday->id, $anniversary->id],
                'any_interest_ids' => [$tech->id],
            ])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing([$birthdayGift->id, $anniversaryGift->id], $listingIds);

        $interestCounts = $this->countFacets(
            DiscoveryListingContext::forRelationship($husband),
            new DiscoveryListingQueryState(occasionSlugs: ['anniversary', 'birthday']),
            'interest',
            [$tech->id],
        );

        $this->assertSame(2, $interestCounts[$tech->id] ?? 0);
    }

    public function test_budget_facets_are_disjunctive_and_use_half_open_ranges(): void
    {
        $this->seed(BudgetRangeSeeder::class);

        $husband = $this->relationship('Husband');
        $at999 = $this->gift('price-999', [$husband], [], [], '999.99');
        $at1000 = $this->gift('price-1000', [$husband], [], [], '1000.00');
        $at2499 = $this->gift('price-2499', [$husband], [], [], '2499.99');

        $rangeIds = BudgetRange::query()->orderBy('sort_order')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $bySlug = BudgetRange::query()->pluck('id', 'slug')->map(fn ($id) => (int) $id);

        $counts = $this->countFacets(
            DiscoveryListingContext::forRelationship($husband),
            new DiscoveryListingQueryState(budgetSlug: '1000-2500'),
            'budget',
            $rangeIds,
        );

        $this->assertSame(1, $counts[$bySlug['500-1000']] ?? 0);
        $this->assertSame(2, $counts[$bySlug['1000-2500']] ?? 0);
        $this->assertContains($at999->id, $this->listingIds($husband, '500-1000'));
        $this->assertContains($at1000->id, $this->listingIds($husband, '1000-2500'));
        $this->assertContains($at2499->id, $this->listingIds($husband, '1000-2500'));
        $this->assertNotContains($at1000->id, $this->listingIds($husband, '500-1000'));
    }

    public function test_child_category_ids_are_counted_without_parent_rollup(): void
    {
        $husband = $this->relationship('Husband');
        $fashion = Category::query()->create([
            'name' => 'Fashion & Accessories',
            'slug' => 'fashion-and-accessories',
            'is_active' => true,
        ]);
        $jewellery = Category::query()->create([
            'parent_id' => $fashion->id,
            'name' => 'Jewellery',
            'slug' => 'jewellery',
            'is_active' => true,
        ]);
        $home = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        $kitchen = Category::query()->create([
            'parent_id' => $home->id,
            'name' => 'Kitchen & Dining',
            'slug' => 'kitchen-and-dining',
            'is_active' => true,
        ]);

        $gift = $this->gift('necklace', [$husband]);
        $gift->categories()->attach($jewellery);

        $counts = $this->countFacets(
            DiscoveryListingContext::forRelationship($husband),
            new DiscoveryListingQueryState,
            'category',
            [$fashion->id, $jewellery->id, $home->id, $kitchen->id],
        );

        $this->assertSame(1, $counts[$jewellery->id] ?? 0);
        $this->assertSame(0, $counts[$fashion->id] ?? 0);
        $this->assertSame(0, $counts[$kitchen->id] ?? 0);
        $this->assertSame(0, $counts[$home->id] ?? 0);
    }

    public function test_drafts_are_not_counted(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');

        $this->gift('published', [$husband], [$birthday]);

        $draft = Product::factory()->draft()->create(['slug' => 'draft-gift']);
        $draft->relationships()->attach($husband);
        $draft->occasions()->attach($birthday);

        $archived = Product::factory()->create([
            'slug' => 'archived-gift',
            'status' => ProductStatus::Archived,
            'published_at' => now(),
        ]);
        $archived->relationships()->attach($husband);
        $archived->occasions()->attach($birthday);

        $counts = $this->countFacets(
            DiscoveryListingContext::forRelationship($husband),
            new DiscoveryListingQueryState,
            'occasion',
            [$birthday->id],
        );

        $this->assertSame(1, $counts[$birthday->id] ?? 0);
    }

    public function test_seo_landing_page_honors_multiple_fixed_constraints(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $tech = $this->interest('Tech & Gadgets', 'technology');
        $travel = $this->interest('Travel', 'travel');

        $this->gift('tech-birthday', [$husband], [$birthday], [$tech]);
        $this->gift('travel-birthday', [$husband], [$birthday], [$travel]);
        $this->gift('tech-only', [$husband], [], [$tech]);

        $context = new DiscoveryListingContext(
            surface: 'seo_landing',
            fixedFilters: [
                'relationship_id' => $husband->id,
                'occasion_id' => $birthday->id,
            ],
            availableDimensions: ['interest', 'budget', 'gift_type', 'category'],
            browseContext: 'seo:birthday-gifts-for-husband',
        );

        $counts = $this->countFacets($context, new DiscoveryListingQueryState, 'interest', [$tech->id, $travel->id]);

        $this->assertSame(1, $counts[$tech->id] ?? 0);
        $this->assertSame(1, $counts[$travel->id] ?? 0);
    }

    public function test_every_supported_dimension_is_disjunctive(): void
    {
        $husband = $this->relationship('Husband');
        $wife = $this->relationship('Wife');
        $birthday = $this->occasion('Birthday');
        $anniversary = $this->occasion('Anniversary');
        $tech = $this->interest('Tech & Gadgets', 'technology');
        $travel = $this->interest('Travel', 'travel');
        $personalized = $this->giftType('Personalized Gifts', 'personalized-gifts');
        $experience = $this->giftType('Experience Gifts', 'experience-gifts');
        $adult = $this->recipientType('Adult');
        $kids = $this->recipientType('Kids');
        $engineer = $this->profession('Engineer');
        $teacher = $this->profession('Teacher');
        $fashion = Category::query()->create(['name' => 'Fashion', 'slug' => 'fashion', 'is_active' => true]);
        $home = Category::query()->create(['name' => 'Home', 'slug' => 'home', 'is_active' => true]);
        $under = BudgetRange::query()->create([
            'name' => 'Under ₹500',
            'slug' => 'under-500',
            'min_amount' => null,
            'max_amount' => '500.00',
            'currency' => 'INR',
            'is_active' => true,
        ]);
        $mid = BudgetRange::query()->create([
            'name' => '₹1,000–₹2,500',
            'slug' => '1000-2500',
            'min_amount' => '1000.00',
            'max_amount' => '2500.00',
            'currency' => 'INR',
            'is_active' => true,
        ]);

        $giftA = $this->gift('a', [$husband], [$birthday], [$tech], '200.00');
        $giftA->giftTypes()->attach($personalized);
        $giftA->recipientTypes()->attach($adult);
        $giftA->professions()->attach($engineer);
        $giftA->categories()->attach($fashion);

        $giftB = $this->gift('b', [$wife], [$anniversary], [$travel], '1500.00');
        $giftB->giftTypes()->attach($experience);
        $giftB->recipientTypes()->attach($kids);
        $giftB->professions()->attach($teacher);
        $giftB->categories()->attach($home);

        $context = DiscoveryListingContext::forGiftIdeas();

        $this->assertSame(1, $this->countFacets($context, new DiscoveryListingQueryState(occasionSlugs: ['birthday']), 'occasion', [$birthday->id, $anniversary->id])[$anniversary->id] ?? 0);
        $this->assertSame(1, $this->countFacets($context, new DiscoveryListingQueryState(relationshipSlugs: ['husband']), 'relationship', [$husband->id, $wife->id])[$wife->id] ?? 0);
        $this->assertSame(1, $this->countFacets($context, new DiscoveryListingQueryState(interestSlugs: ['technology']), 'interest', [$tech->id, $travel->id])[$travel->id] ?? 0);
        $this->assertSame(1, $this->countFacets($context, new DiscoveryListingQueryState(giftTypeSlugs: ['personalized-gifts']), 'gift_type', [$personalized->id, $experience->id])[$experience->id] ?? 0);
        $this->assertSame(1, $this->countFacets($context, new DiscoveryListingQueryState(recipientSlugs: ['adult']), 'recipient', [$adult->id, $kids->id])[$kids->id] ?? 0);
        $this->assertSame(1, $this->countFacets($context, new DiscoveryListingQueryState(professionSlugs: ['engineer']), 'profession', [$engineer->id, $teacher->id])[$teacher->id] ?? 0);
        $this->assertSame(1, $this->countFacets($context, new DiscoveryListingQueryState(categoryPaths: ['fashion']), 'category', [$fashion->id, $home->id])[$home->id] ?? 0);
        $this->assertSame(1, $this->countFacets($context, new DiscoveryListingQueryState(budgetSlug: 'under-500'), 'budget', [$under->id, $mid->id])[$mid->id] ?? 0);
    }

    public function test_facet_query_count_does_not_grow_with_option_count(): void
    {
        $husband = $this->relationship('Husband');
        $small = [];
        $large = [];

        foreach (range(1, 20) as $index) {
            $occasion = $this->occasion("Occasion {$index}");
            $large[] = $occasion->id;

            if ($index <= 2) {
                $small[] = $occasion->id;
                $this->gift("gift-{$index}", [$husband], [$occasion]);
            }
        }

        $context = DiscoveryListingContext::forRelationship($husband);
        $state = new DiscoveryListingQueryState;
        $action = app(CountDiscoveryFilterFacetsAction::class);

        $first = $this->countQueries(fn () => $action->execute($context, $state, 'occasion', $small));
        $second = $this->countQueries(fn () => $action->execute($context, $state, 'occasion', $large));

        $this->assertSame($first, $second);
        $this->assertLessThanOrEqual(3, $first);
    }

    /**
     * @param  list<int>  $candidateIds
     * @return array<int, int>
     */
    private function countFacets(
        DiscoveryListingContext $listing,
        DiscoveryListingQueryState $state,
        string $dimension,
        array $candidateIds,
    ): array {
        return app(CountDiscoveryFilterFacetsAction::class)
            ->execute($listing, $state, $dimension, $candidateIds);
    }

    /**
     * @param  list<Relationship>  $relationships
     * @param  list<Occasion>  $occasions
     * @param  list<Interest>  $interests
     */
    private function gift(
        string $slug,
        array $relationships = [],
        array $occasions = [],
        array $interests = [],
        string $price = '1500.00',
    ): Product {
        $product = Product::factory()->published()->create([
            'slug' => $slug,
            'price_amount' => $price,
            'price_currency' => 'INR',
        ]);

        if ($relationships !== []) {
            $product->relationships()->attach(collect($relationships)->pluck('id')->all());
        }
        if ($occasions !== []) {
            $product->occasions()->attach(collect($occasions)->pluck('id')->all());
        }
        if ($interests !== []) {
            $product->interests()->attach(collect($interests)->pluck('id')->all());
        }

        return $product;
    }

    /**
     * @return list<int>
     */
    private function listingIds(Relationship $husband, string $budgetSlug): array
    {
        $id = (int) BudgetRange::query()->where('slug', $budgetSlug)->value('id');

        return app(QueryPublishedProductsByFiltersAction::class)
            ->execute([
                'relationship_id' => $husband->id,
                'budget_range_id' => $id,
            ])
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function relationship(string $name): Relationship
    {
        return Relationship::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function occasion(string $name): Occasion
    {
        return Occasion::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function interest(string $name, string $slug): Interest
    {
        return Interest::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    private function giftType(string $name, string $slug): GiftType
    {
        return GiftType::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    private function recipientType(string $name): RecipientType
    {
        return RecipientType::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function profession(string $name): Profession
    {
        return Profession::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();

        return count(DB::getQueryLog());
    }
}
