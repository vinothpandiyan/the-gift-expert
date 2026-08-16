<?php

namespace Tests\Unit\Actions;

use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Merchant;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class QueryPublishedProductsByFiltersActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_filter_returns_only_matching_products(): void
    {
        $husband = $this->relationship('Husband');
        $father = $this->relationship('Father');

        $husbandGift = $this->publishedGift('husband-gift');
        $husbandGift->relationships()->attach($husband);

        $fatherGift = $this->publishedGift('father-gift');
        $fatherGift->relationships()->attach($father);

        $ids = $this->filteredIds(['relationship_id' => $husband->id]);

        $this->assertSame([$husbandGift->id], $ids);
    }

    public function test_occasion_is_a_hard_filter(): void
    {
        $birthday = $this->occasion('Birthday');
        $anniversary = $this->occasion('Anniversary');

        $birthdayGift = $this->publishedGift('birthday-gift');
        $birthdayGift->occasions()->attach($birthday);

        $anniversaryGift = $this->publishedGift('anniversary-gift');
        $anniversaryGift->occasions()->attach($anniversary);

        $ids = $this->filteredIds(['occasion_id' => $birthday->id]);

        $this->assertSame([$birthdayGift->id], $ids);
    }

    public function test_relationship_and_occasion_require_both_matches(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');

        $both = $this->publishedGift('birthday-husband');
        $both->relationships()->attach($husband);
        $both->occasions()->attach($birthday);

        $husbandOnly = $this->publishedGift('husband-only');
        $husbandOnly->relationships()->attach($husband);

        $birthdayOnly = $this->publishedGift('birthday-only');
        $birthdayOnly->occasions()->attach($birthday);

        $ids = $this->filteredIds([
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
        ]);

        $this->assertSame([$both->id], $ids);
    }

    public function test_recipient_type_filter_returns_only_matching_products(): void
    {
        $adult = $this->recipientType('Adult');
        $kids = $this->recipientType('Kids');

        $adultGift = $this->publishedGift('adult-gift');
        $adultGift->recipientTypes()->attach($adult);

        $kidsGift = $this->publishedGift('kids-gift');
        $kidsGift->recipientTypes()->attach($kids);

        $ids = $this->filteredIds(['recipient_type_id' => $adult->id]);

        $this->assertSame([$adultGift->id], $ids);
    }

    public function test_profession_filter_returns_only_matching_products(): void
    {
        $engineer = $this->profession('Engineer');
        $teacher = $this->profession('Teacher');

        $engineerGift = $this->publishedGift('engineer-gift');
        $engineerGift->professions()->attach($engineer);

        $teacherGift = $this->publishedGift('teacher-gift');
        $teacherGift->professions()->attach($teacher);

        $ids = $this->filteredIds(['profession_id' => $engineer->id]);

        $this->assertSame([$engineerGift->id], $ids);
    }

    public function test_gift_type_filter_returns_only_matching_products(): void
    {
        $giftCards = $this->giftType('Gift Cards', 'gift-cards');
        $subscriptions = $this->giftType('Subscriptions', 'subscriptions');

        $cardGift = $this->publishedGift('card-gift');
        $cardGift->giftTypes()->attach($giftCards);

        $subscriptionGift = $this->publishedGift('subscription-gift');
        $subscriptionGift->giftTypes()->attach($subscriptions);

        $ids = $this->filteredIds(['gift_type_id' => $giftCards->id]);

        $this->assertSame([$cardGift->id], $ids);
    }

    public function test_category_relationship_and_occasion_require_all_matches(): void
    {
        $electronics = Category::query()->create([
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);
        $books = Category::query()->create([
            'name' => 'Books',
            'slug' => 'books',
        ]);
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');

        $all = $this->publishedGift('all-three');
        $all->categories()->attach($electronics);
        $all->relationships()->attach($husband);
        $all->occasions()->attach($birthday);

        $missingOccasion = $this->publishedGift('missing-occasion');
        $missingOccasion->categories()->attach($electronics);
        $missingOccasion->relationships()->attach($husband);

        $wrongCategory = $this->publishedGift('wrong-category');
        $wrongCategory->categories()->attach($books);
        $wrongCategory->relationships()->attach($husband);
        $wrongCategory->occasions()->attach($birthday);

        $ids = $this->filteredIds([
            'category_id' => $electronics->id,
            'relationship_id' => $husband->id,
            'occasion_id' => $birthday->id,
        ]);

        $this->assertSame([$all->id], $ids);
    }

    public function test_category_filter_returns_only_matching_products(): void
    {
        $electronics = Category::query()->create([
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);
        $books = Category::query()->create([
            'name' => 'Books',
            'slug' => 'books',
        ]);

        $electronicsGift = $this->publishedGift('electronics-gift');
        $electronicsGift->categories()->attach($electronics);

        $booksGift = $this->publishedGift('books-gift');
        $booksGift->categories()->attach($books);

        $ids = $this->filteredIds(['category_id' => $electronics->id]);

        $this->assertSame([$electronicsGift->id], $ids);
    }

    public function test_single_interest_filter_returns_matching_products(): void
    {
        $coffee = $this->interest('Coffee');
        $travel = $this->interest('Travel');

        $coffeeGift = $this->publishedGift('coffee-gift');
        $coffeeGift->interests()->attach($coffee);

        $travelGift = $this->publishedGift('travel-gift');
        $travelGift->interests()->attach($travel);

        $ids = $this->filteredIds(['interest_ids' => [$coffee->id]]);

        $this->assertSame([$coffeeGift->id], $ids);
    }

    public function test_multiple_interests_require_all_matches(): void
    {
        $coffee = $this->interest('Coffee');
        $technology = $this->interest('Technology');

        $both = $this->publishedGift('coffee-and-tech');
        $both->interests()->attach([$coffee->id, $technology->id]);

        $coffeeOnly = $this->publishedGift('coffee-only');
        $coffeeOnly->interests()->attach($coffee);

        $techOnly = $this->publishedGift('tech-only');
        $techOnly->interests()->attach($technology);

        $ids = $this->filteredIds([
            'interest_ids' => [$coffee->id, $technology->id],
        ]);

        $this->assertSame([$both->id], $ids);
        $this->assertNotContains($coffeeOnly->id, $ids);
        $this->assertNotContains($techOnly->id, $ids);
    }

    public function test_budget_applies_minimum_maximum_currency_and_excludes_null_prices(): void
    {
        $range = BudgetRange::query()->create([
            'name' => '₹500–₹1,000',
            'slug' => '500-1000',
            'min_amount' => '500.00',
            'max_amount' => '1000.00',
            'currency' => 'INR',
        ]);

        $inRange = $this->publishedGift('in-range', ['price_amount' => '750.00', 'price_currency' => 'INR']);
        $belowMin = $this->publishedGift('below-min', ['price_amount' => '499.99', 'price_currency' => 'INR']);
        $aboveMax = $this->publishedGift('above-max', ['price_amount' => '1000.01', 'price_currency' => 'INR']);
        $wrongCurrency = $this->publishedGift('usd', ['price_amount' => '750.00', 'price_currency' => 'USD']);
        $noPrice = $this->publishedGift('no-price', ['price_amount' => null, 'price_currency' => 'INR']);

        $ids = $this->filteredIds(['budget_range_id' => $range->id]);

        $this->assertSame([$inRange->id], $ids);
        $this->assertNotContains($belowMin->id, $ids);
        $this->assertNotContains($aboveMax->id, $ids);
        $this->assertNotContains($wrongCurrency->id, $ids);
        $this->assertNotContains($noPrice->id, $ids);
    }

    public function test_open_ended_seeded_budget_ranges_are_respected(): void
    {
        $under500 = BudgetRange::query()->create([
            'name' => 'Under ₹500',
            'slug' => 'under-500',
            'min_amount' => null,
            'max_amount' => '499.99',
            'currency' => 'INR',
        ]);
        $tenThousandPlus = BudgetRange::query()->create([
            'name' => '₹10,000+',
            'slug' => '10000-plus',
            'min_amount' => '10000.00',
            'max_amount' => null,
            'currency' => 'INR',
        ]);

        $cheap = $this->publishedGift('cheap', ['price_amount' => '200.00']);
        $mid = $this->publishedGift('mid', ['price_amount' => '750.00']);
        $expensive = $this->publishedGift('expensive', ['price_amount' => '15000.00']);

        $this->assertSame([$cheap->id], $this->filteredIds(['budget_range_id' => $under500->id]));
        $this->assertSame([$expensive->id], $this->filteredIds(['budget_range_id' => $tenThousandPlus->id]));
        $this->assertNotContains($mid->id, $this->filteredIds(['budget_range_id' => $under500->id]));
        $this->assertNotContains($mid->id, $this->filteredIds(['budget_range_id' => $tenThousandPlus->id]));
    }

    public function test_unpublished_products_are_never_returned(): void
    {
        $husband = $this->relationship('Husband');

        $published = $this->publishedGift('published-husband');
        $published->relationships()->attach($husband);

        $draft = Product::factory()->draft()->create(['slug' => 'draft-husband']);
        $draft->relationships()->attach($husband);

        $archived = Product::factory()->create([
            'slug' => 'archived-husband',
            'status' => ProductStatus::Archived,
            'published_at' => now(),
        ]);
        $archived->relationships()->attach($husband);

        $ids = $this->filteredIds(['relationship_id' => $husband->id]);

        $this->assertSame([$published->id], $ids);
    }

    public function test_empty_filters_are_rejected(): void
    {
        $this->publishedGift('catalog-gift');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one product filter is required.');

        app(QueryPublishedProductsByFiltersAction::class)->execute([]);
    }

    public function test_unfiltered_query_requires_explicit_opt_in(): void
    {
        $gift = $this->publishedGift('catalog-gift');

        $ids = app(QueryPublishedProductsByFiltersAction::class)
            ->execute([], allowUnfiltered: true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([$gift->id], $ids);
    }

    public function test_active_affiliate_is_not_required_by_default(): void
    {
        $husband = $this->relationship('Husband');

        $withoutLink = $this->publishedGift('no-affiliate');
        $withoutLink->relationships()->attach($husband);

        $withLink = $this->publishedGift('with-affiliate');
        $this->attachAffiliate($withLink);
        $withLink->relationships()->attach($husband);

        $ids = $this->filteredIds(['relationship_id' => $husband->id]);

        $this->assertEqualsCanonicalizing([$withoutLink->id, $withLink->id], $ids);
    }

    public function test_require_active_affiliate_excludes_products_without_active_links(): void
    {
        $husband = $this->relationship('Husband');

        $withoutLink = $this->publishedGift('no-affiliate');
        $withoutLink->relationships()->attach($husband);

        $inactive = $this->publishedGift('inactive-affiliate');
        $this->attachAffiliate($inactive, AffiliateLinkStatus::Inactive);
        $inactive->relationships()->attach($husband);

        $active = $this->publishedGift('active-affiliate');
        $this->attachAffiliate($active);
        $active->relationships()->attach($husband);

        $ids = app(QueryPublishedProductsByFiltersAction::class)
            ->execute(['relationship_id' => $husband->id], requireActiveAffiliate: true)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([$active->id], $ids);
    }

    public function test_interest_or_matching_is_available_for_recommendation_reuse(): void
    {
        $coffee = $this->interest('Coffee');
        $technology = $this->interest('Technology');

        $both = $this->publishedGift('both');
        $both->interests()->attach([$coffee->id, $technology->id]);

        $coffeeOnly = $this->publishedGift('coffee-only');
        $coffeeOnly->interests()->attach($coffee);

        $ids = app(QueryPublishedProductsByFiltersAction::class)
            ->execute(
                ['interest_ids' => [$coffee->id, $technology->id]],
                matchAllInterests: false,
            )
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing([$both->id, $coffeeOnly->id], $ids);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function filteredIds(array $filters): array
    {
        return app(QueryPublishedProductsByFiltersAction::class)
            ->execute($filters)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function publishedGift(string $slug, array $attributes = []): Product
    {
        return Product::factory()->published()->create(array_merge([
            'slug' => $slug,
            'price_amount' => '500.00',
            'price_currency' => 'INR',
        ], $attributes));
    }

    private function attachAffiliate(Product $product, AffiliateLinkStatus $status = AffiliateLinkStatus::Active): void
    {
        $merchant = Merchant::query()->firstOrCreate(
            ['slug' => 'example-merchant'],
            [
                'name' => 'Example Merchant',
                'affiliate_network' => 'example',
                'is_active' => true,
            ],
        );

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://example.com/'.$product->slug,
            'status' => $status,
            'is_primary' => true,
        ]);
    }

    private function relationship(string $name): Relationship
    {
        return Relationship::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
    }

    private function occasion(string $name): Occasion
    {
        return Occasion::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
    }

    private function recipientType(string $name): RecipientType
    {
        return RecipientType::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
    }

    private function profession(string $name): Profession
    {
        return Profession::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
    }

    private function giftType(string $name, string $slug): GiftType
    {
        return GiftType::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    private function interest(string $name): Interest
    {
        return Interest::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
    }
}
