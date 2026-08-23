<?php

namespace Tests\Support;

use App\Enums\ProductStatus;
use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Discovery\GiftCatalogTestHelpers;

trait SeedsDiscoveryRankingCatalog
{
    use RefreshDatabase;

    protected Relationship $husband;

    protected Relationship $wife;

    protected Relationship $boyfriend;

    protected Relationship $colleague;

    protected Relationship $father;

    protected Relationship $brother;

    protected Relationship $friends;

    protected Occasion $birthday;

    protected Occasion $anniversary;

    protected Occasion $housewarming;

    protected Category $electronics;

    protected Category $homeAndLiving;

    protected Category $fashion;

    protected Category $personalized;

    protected BudgetRange $underFiveHundred;

    protected Product $alarmClock;

    protected Product $romanticLamp;

    protected Product $wallet;

    protected Product $laptopSleeve;

    protected Product $coffeeMug;

    protected Product $secondClock;

    protected function seedDiscoveryRankingCatalog(): void
    {
        $this->husband = $this->relationship('Husband', 'husband');
        $this->wife = $this->relationship('Wife', 'wife');
        $this->boyfriend = $this->relationship('Boyfriend', 'boyfriend');
        $this->colleague = $this->relationship('Colleagues', 'colleagues');
        $this->father = $this->relationship('Father', 'father');
        $this->brother = $this->relationship('Brother', 'brother');
        $this->friends = $this->relationship('Friends', 'friends');

        $this->birthday = $this->occasion('Birthday', 'birthday');
        $this->anniversary = $this->occasion('Anniversary', 'anniversary');
        $this->housewarming = $this->occasion('Housewarming', 'housewarming');

        $this->electronics = $this->category('Electronics', 'electronics');
        $this->homeAndLiving = $this->category('Home & Living', 'home-and-living');
        $this->fashion = $this->category('Fashion & Accessories', 'fashion-and-accessories');
        $this->personalized = $this->category('Personalized Gifts', 'personalized-gifts');

        $this->underFiveHundred = BudgetRange::query()->create([
            'name' => 'Under ₹500',
            'slug' => 'under-500',
            'min_amount' => null,
            'max_amount' => '500.00',
            'currency' => 'INR',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $broadRelationships = collect([
            $this->husband,
            $this->wife,
            $this->boyfriend,
            $this->colleague,
            $this->father,
            $this->brother,
            $this->friends,
        ])->pluck('id')->all();

        $this->alarmClock = $this->catalogGift('Digital Portable Alarm Clock for Desk', 'alarm-clock', [
            'price_amount' => '299.00',
            'price_currency' => 'INR',
            'published_at' => now()->subDays(2),
        ], $broadRelationships, [$this->birthday->id, $this->housewarming->id, $this->anniversary->id], $this->electronics);

        $this->romanticLamp = $this->catalogGift('Personalized Romantic Lamp', 'romantic-lamp', [
            'price_amount' => '899.00',
            'price_currency' => 'INR',
            'published_at' => now()->subDay(),
        ], [$this->husband->id, $this->boyfriend->id], [$this->birthday->id, $this->anniversary->id], $this->personalized);

        $this->wallet = $this->catalogGift('Men\'s Leather Wallet', 'mens-wallet', [
            'price_amount' => '949.00',
            'price_currency' => 'INR',
        ], [$this->father->id, $this->husband->id, $this->brother->id], [$this->birthday->id], $this->fashion);

        $this->laptopSleeve = $this->catalogGift('Laptop Sleeve', 'laptop-sleeve', [
            'price_amount' => '599.00',
            'price_currency' => 'INR',
        ], [$this->colleague->id, $this->friends->id, $this->husband->id], [$this->birthday->id], $this->electronics);

        $this->coffeeMug = $this->catalogGift('Coffee Mug', 'coffee-mug', [
            'price_amount' => '399.00',
            'price_currency' => 'INR',
        ], $broadRelationships, [$this->birthday->id], $this->homeAndLiving);

        $this->secondClock = $this->catalogGift('Digital Alarm Clock for Desk', 'second-clock', [
            'price_amount' => '349.00',
            'price_currency' => 'INR',
        ], $broadRelationships, [$this->birthday->id], $this->electronics);
    }

    /**
     * @param  list<int>  $relationshipIds
     * @param  list<int>  $occasionIds
     */
    protected function catalogGift(
        string $name,
        string $slug,
        array $attributes,
        array $relationshipIds,
        array $occasionIds,
        Category $primaryCategory,
        ?Category $secondaryCategory = null,
    ): Product {
        $product = GiftCatalogTestHelpers::publishedGift(array_merge([
            'name' => $name,
            'slug' => $slug,
            'status' => ProductStatus::Published,
        ], $attributes));

        $product->relationships()->sync($relationshipIds);
        $product->occasions()->sync($occasionIds);

        $sync = [
            $primaryCategory->id => ['is_primary' => true],
        ];

        if ($secondaryCategory instanceof Category) {
            $sync[$secondaryCategory->id] = ['is_primary' => false];
        }

        $product->categories()->sync($sync);

        return $product->fresh([
            'categories',
            'relationships',
            'occasions',
            'interests',
            'recipientTypes',
            'professions',
            'giftTypes',
        ]);
    }

    protected function relationship(string $name, string $slug): Relationship
    {
        return Relationship::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    protected function occasion(string $name, string $slug): Occasion
    {
        return Occasion::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    protected function category(string $name, string $slug): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }
}
