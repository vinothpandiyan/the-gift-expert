<?php

namespace Tests\Feature\Discovery;

use App\DiscoveryListing\DiscoveryListingContext;
use App\Livewire\GiftListing;
use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class GiftListingFilterUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_filter_controls_use_correct_types_unique_ids_and_labels(): void
    {
        $husband = $this->relationship('Husband');
        $birthday = $this->occasion('Birthday');
        BudgetRange::query()->create([
            'name' => '₹1,000–₹2,500',
            'slug' => '1000-2500',
            'min_amount' => '1000.00',
            'max_amount' => '2500.00',
            'currency' => 'INR',
            'is_active' => true,
        ]);

        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Birthday Wallet', 'slug' => 'birthday-wallet', 'price_amount' => '1499.00'],
            ['relationships' => $husband, 'occasions' => $birthday],
        );

        $html = $this->get(DiscoveryUrl::relationship('husband'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/id="desktop-occasion-birthday"[^>]*type="checkbox"|type="checkbox"[^>]*id="desktop-occasion-birthday"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/id="mobile-occasion-birthday"[^>]*type="checkbox"|type="checkbox"[^>]*id="mobile-occasion-birthday"/',
            $html,
        );
        $this->assertSame(1, substr_count($html, 'id="desktop-occasion-birthday"'));
        $this->assertSame(1, substr_count($html, 'id="mobile-occasion-birthday"'));
        $this->assertStringContainsString('for="desktop-occasion-birthday"', $html);
        $this->assertStringContainsString('for="mobile-occasion-birthday"', $html);
        $this->assertStringContainsString('form-control-checkbox', $html);
        $this->assertStringContainsString('form-control-radio', $html);
        $this->assertStringContainsString('id="desktop-budget-1000-2500"', $html);
        $this->assertMatchesRegularExpression(
            '/id="desktop-budget-1000-2500"[^>]*type="radio"|type="radio"[^>]*id="desktop-budget-1000-2500"/',
            $html,
        );
        $this->assertStringNotContainsString('wire:click.prevent="toggleFilter', $html);
        $this->assertStringContainsString('wire:change="setFilter(', $html);
        $this->assertStringContainsString('Birthday, 1 gift idea', $html);
        $this->assertStringContainsString('filter-option-count', $html);
        $this->assertStringContainsString('<fieldset', $html);
        $this->assertStringContainsString('id="desktop-occasion-heading"', $html);
        $this->assertStringContainsString('border-b border-line py-2', $html);
        $this->assertStringContainsString('rounded-xl border border-line bg-surface px-3', $html);
        $this->assertStringContainsString('mt-0.5 flex flex-col', $html);
        $this->assertStringContainsString('min-h-11', $html);
    }

    public function test_selected_zero_count_option_can_be_cleared(): void
    {
        $husband = $this->relationship('Husband');
        $this->occasion('Birthday');
        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Unfiltered Gift', 'slug' => 'unfiltered-zero-clear'],
            ['relationships' => $husband],
        );

        Livewire::withQueryParams(['occasion' => 'birthday'])
            ->test(GiftListing::class, [
                'context' => DiscoveryListingContext::forRelationship($husband)->toArray(),
            ])
            ->assertSeeHtml('filter-option-count')
            ->assertSee('Birthday')
            ->call('setFilter', 'occasion', 'birthday', false)
            ->assertSet('occasion', '');
    }

    public function test_category_children_render_beneath_parents_without_queries(): void
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

        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Parent Fashion Gift', 'slug' => 'parent-fashion'],
            ['relationships' => $husband, 'categories' => $fashion],
        );
        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Gold Necklace', 'slug' => 'gold-necklace'],
            ['relationships' => $husband, 'categories' => $jewellery],
        );
        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Parent Home Gift', 'slug' => 'parent-home'],
            ['relationships' => $husband, 'categories' => $home],
        );
        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Serving Bowl', 'slug' => 'serving-bowl'],
            ['relationships' => $husband, 'categories' => $kitchen],
        );

        $this->get(DiscoveryUrl::relationship('husband'))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get(DiscoveryUrl::relationship('husband'))->assertOk()->getContent();
        $queryCount = count(DB::getQueryLog());

        $fashionPos = strpos($html, 'Fashion &amp; Accessories');
        $jewelleryPos = strpos($html, 'Jewellery');
        $homePos = strpos($html, 'Home &amp; Living');
        $kitchenPos = strpos($html, 'Kitchen &amp; Dining');

        $this->assertNotFalse($fashionPos);
        $this->assertNotFalse($jewelleryPos);
        $this->assertLessThan($jewelleryPos, $fashionPos);
        $this->assertLessThan($kitchenPos, $homePos);
        $this->assertStringContainsString('Jewellery', $html);
        $this->assertStringContainsString('Kitchen &amp; Dining', $html);
        $this->assertMatchesRegularExpression('/Jewellery[\s\S]{0,120}\(1\)/', $html);
        $this->assertMatchesRegularExpression('/Kitchen &amp; Dining[\s\S]{0,120}\(1\)/', $html);
        $this->assertMatchesRegularExpression('/pl-7[^>]*>[\s\S]*Jewellery/', $html);
        $this->assertLessThanOrEqual(80, $queryCount);
    }

    public function test_empty_filter_groups_are_omitted(): void
    {
        $husband = $this->relationship('Husband');
        GiftType::query()->create([
            'name' => 'Experience Gifts',
            'slug' => 'experience-gifts',
            'is_active' => true,
        ]);
        GiftCatalogTestHelpers::taggedGift(
            ['name' => 'Plain Gift', 'slug' => 'plain-gift'],
            ['relationships' => $husband],
        );

        $html = $this->get(DiscoveryUrl::relationship('husband'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="desktop-gift_type-heading"', $html);
        $this->assertStringNotContainsString('id="desktop-gift_type-panel"', $html);
        $this->assertStringNotContainsString('Experience Gifts', $html);
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
}
