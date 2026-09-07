<?php

namespace Tests\Unit\Actions;

use App\Actions\Discovery\ResolveDiscoveryFilterOptionsAction;
use App\DiscoveryListing\DiscoveryFilterOption;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\TaxonomyApplicabilityRule;
use Database\Seeders\BudgetRangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResolveDiscoveryFilterOptionsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_husband_page_hides_semantic_and_zero_count_occasions(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $anniversary = $this->occasion('Anniversary');
        $babyShower = $this->occasion('Baby Shower');
        $raksha = $this->occasion('Raksha Bandhan');
        $bridalShower = $this->occasion('Bridal Shower');
        $tech = $this->interest('Tech & Gadgets', 'technology');
        $experience = $this->giftType('Experience Gifts', 'experience-gifts');

        $this->exclude($husband, TaxonomyDimension::Relationship, $babyShower, TaxonomyDimension::Occasion);
        $this->allow($raksha, TaxonomyDimension::Occasion, $this->relationship('Brother'), TaxonomyDimension::Relationship);
        $this->allow($bridalShower, TaxonomyDimension::Occasion, $this->relationship('Sister'), TaxonomyDimension::Relationship);

        $this->gift('a', [$husband], [$birthday], [$tech]);
        $this->gift('b', [$husband], [$anniversary], [$tech]);
        $this->gift('bad-tag', [$husband], [$raksha], [$tech]);

        $options = $this->filterOptions(DiscoveryListingContext::forRelationship($husband));
        $occasions = $this->keyed($options['occasion']);

        $this->assertArrayHasKey('birthday', $occasions);
        $this->assertArrayHasKey('anniversary', $occasions);
        $this->assertSame(1, $occasions['birthday']->count);
        $this->assertSame(1, $occasions['anniversary']->count);
        $this->assertArrayNotHasKey('baby-shower', $occasions);
        $this->assertArrayNotHasKey('raksha-bandhan', $occasions);
        $this->assertArrayNotHasKey('bridal-shower', $occasions);
        $this->assertArrayNotHasKey('experience-gifts', $this->keyed($options['gift_type']));
        $this->assertTrue($options['gift_type']->doesntContain(fn (DiscoveryFilterOption $option) => $option->slug === $experience->slug));
    }

    public function test_selected_zero_count_option_remains_visible(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $this->gift('unfiltered', [$husband]);

        $options = $this->filterOptions(
            DiscoveryListingContext::forRelationship($husband),
            new DiscoveryListingQueryState(occasionSlugs: ['birthday']),
        );

        $birthdayOption = $this->keyed($options['occasion'])['birthday'];
        $this->assertTrue($birthdayOption->selected);
        $this->assertSame(0, $birthdayOption->count);
        $this->assertSame($birthday->id, $birthdayOption->id);
    }

    public function test_inactive_taxonomy_cannot_appear(): void
    {
        $husband = $this->relationship('Husband');
        $inactive = $this->occasion('Festival');
        $inactive->update(['is_active' => false]);
        $this->gift('a', [$husband], [$inactive]);

        $options = $this->filterOptions(DiscoveryListingContext::forRelationship($husband));

        $this->assertArrayNotHasKey('festival', $this->keyed($options['occasion'] ?? collect()));
    }

    public function test_inactive_composite_category_cannot_be_resurrected(): void
    {
        $husband = $this->relationship('Husband');
        $legacy = Category::query()->create([
            'name' => 'Gifts for Husband',
            'slug' => 'gifts-for-husband',
            'is_active' => false,
        ]);
        $gift = $this->gift('watch', [$husband]);
        $gift->categories()->attach($legacy);

        $options = $this->filterOptions(DiscoveryListingContext::forRelationship($husband));

        $this->assertTrue(
            ($options['category'] ?? collect())->doesntContain(
                fn (DiscoveryFilterOption $option) => $option->slug === 'gifts-for-husband',
            ),
        );
    }

    public function test_page_own_dimension_is_omitted_and_tech_interest_constrains_counts(): void
    {
        $tech = $this->interest('Tech & Gadgets', 'technology');
        $husband = $this->relationship('Husband');
        $wife = $this->relationship('Wife');
        $birthday = $this->occasion('Birthday');

        $this->gift('tech-husband', [$husband], [$birthday], [$tech]);
        $this->gift('wife-only', [$wife], [$birthday]);

        $options = $this->filterOptions(DiscoveryListingContext::forInterest($tech));

        $this->assertArrayNotHasKey('interest', $options);
        $this->assertArrayHasKey('husband', $this->keyed($options['relationship']));
        $this->assertArrayNotHasKey('wife', $this->keyed($options['relationship']));
    }

    public function test_gift_ideas_budget_query_constrains_other_dimensions_not_budget(): void
    {
        $this->seed(BudgetRangeSeeder::class);

        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        $this->gift('cheap', [$husband], [$birthday], [], '200.00');
        $this->gift('mid', [$husband], [$birthday], [], '1500.00');

        $options = $this->filterOptions(
            DiscoveryListingContext::forGiftIdeas(),
            new DiscoveryListingQueryState(budgetSlug: '1000-2500'),
        );

        $this->assertSame(1, $this->keyed($options['occasion'])['birthday']->count);
        $this->assertSame(1, $this->keyed($options['relationship'])['husband']->count);
        $this->assertSame(1, $this->keyed($options['budget'])['1000-2500']->count);
        $this->assertSame(1, $this->keyed($options['budget'])['under-500']->count);
    }

    public function test_wife_kids_and_colleague_pages_hide_invalid_values(): void
    {
        $wife = $this->relationship('Wife');
        $kids = $this->recipientType('Kids');
        $colleague = $this->relationship('Colleagues');
        $raksha = $this->occasion('Raksha Bandhan');
        $babyShower = $this->occasion('Baby Shower');
        $birthday = $this->occasion('Birthday');
        $returnGifts = $this->giftType('Return Gifts', 'return-gifts');
        $brother = $this->relationship('Brother');

        $this->allow($raksha, TaxonomyDimension::Occasion, $brother, TaxonomyDimension::Relationship);
        $this->exclude($colleague, TaxonomyDimension::Relationship, $babyShower, TaxonomyDimension::Occasion);
        $this->exclude($kids, TaxonomyDimension::RecipientType, $babyShower, TaxonomyDimension::Occasion);
        $this->exclude($colleague, TaxonomyDimension::Relationship, $returnGifts, TaxonomyDimension::GiftType);

        $this->gift('wife-birthday', [$wife], [$birthday]);
        $this->gift('kids-birthday', [], [$birthday], [], '1500.00', [$kids]);
        $this->gift('colleague-birthday', [$colleague], [$birthday]);

        $wifeOccasions = $this->keyed($this->filterOptions(DiscoveryListingContext::forRelationship($wife))['occasion']);
        $this->assertArrayHasKey('birthday', $wifeOccasions);
        $this->assertArrayNotHasKey('raksha-bandhan', $wifeOccasions);

        $kidsOccasions = $this->keyed($this->filterOptions(DiscoveryListingContext::forRecipientType($kids))['occasion']);
        $this->assertArrayHasKey('birthday', $kidsOccasions);
        $this->assertArrayNotHasKey('baby-shower', $kidsOccasions);

        $colleagueOptions = $this->filterOptions(DiscoveryListingContext::forRelationship($colleague));
        $this->assertArrayNotHasKey('baby-shower', $this->keyed($colleagueOptions['occasion']));
        $this->assertArrayNotHasKey('return-gifts', $this->keyed($colleagueOptions['gift_type']));
    }

    public function test_pipeline_query_count_does_not_grow_with_option_count(): void
    {
        $husband = $this->relationship('Husband');
        $this->exclude(
            $husband,
            TaxonomyDimension::Relationship,
            $this->occasion('Baby Shower'),
            TaxonomyDimension::Occasion,
        );

        foreach (range(1, 2) as $index) {
            $this->gift("small-{$index}", [$husband], [$this->occasion("Small {$index}")]);
        }

        $context = DiscoveryListingContext::forRelationship($husband);
        $action = app(ResolveDiscoveryFilterOptionsAction::class);

        $first = $this->countQueries(fn () => $action->execute($context, new DiscoveryListingQueryState));

        foreach (range(3, 22) as $index) {
            $this->occasion("Large {$index}");
        }

        $second = $this->countQueries(fn () => $action->execute($context, new DiscoveryListingQueryState));

        $this->assertSame($first, $second);
    }

    /**
     * @return array<string, Collection<int, DiscoveryFilterOption>>
     */
    private function filterOptions(
        DiscoveryListingContext $listing,
        ?DiscoveryListingQueryState $state = null,
    ): array {
        return app(ResolveDiscoveryFilterOptionsAction::class)
            ->execute($listing, $state ?? new DiscoveryListingQueryState);
    }

    /**
     * @param  Collection<int, DiscoveryFilterOption>  $options
     * @return array<string, DiscoveryFilterOption>
     */
    private function keyed($options): array
    {
        return $options->keyBy('slug')->all();
    }

    /**
     * @param  list<Relationship>  $relationships
     * @param  list<Occasion>  $occasions
     * @param  list<Interest>  $interests
     * @param  list<RecipientType>  $recipientTypes
     */
    private function gift(
        string $slug,
        array $relationships = [],
        array $occasions = [],
        array $interests = [],
        string $price = '1500.00',
        array $recipientTypes = [],
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
        if ($recipientTypes !== []) {
            $product->recipientTypes()->attach(collect($recipientTypes)->pluck('id')->all());
        }

        return $product;
    }

    private function exclude(
        object $source,
        TaxonomyDimension $sourceDimension,
        object $target,
        TaxonomyDimension $targetDimension,
    ): void {
        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => $sourceDimension,
            'source_id' => $source->id,
            'target_dimension' => $targetDimension,
            'target_id' => $target->id,
            'effect' => TaxonomyApplicabilityEffect::Exclude,
            'reason' => 'test',
            'is_active' => true,
        ]);
    }

    private function allow(
        object $source,
        TaxonomyDimension $sourceDimension,
        object $target,
        TaxonomyDimension $targetDimension,
    ): void {
        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => $sourceDimension,
            'source_id' => $source->id,
            'target_dimension' => $targetDimension,
            'target_id' => $target->id,
            'effect' => TaxonomyApplicabilityEffect::Allow,
            'reason' => 'test',
            'is_active' => true,
        ]);
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

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();

        return count(DB::getQueryLog());
    }
}
