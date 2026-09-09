<?php

namespace Tests\Feature\Filament;

use App\Actions\CuratedCatalog\LoadGiftTaxonomySelectOptionsAction;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Filament\Resources\CatalogSourceLists\Pages\ListCatalogSourceLists;
use App\Filament\Resources\Gifts\Pages\EditGift;
use App\Filament\Resources\Gifts\Pages\ListGifts;
use App\Models\Category;
use App\Models\Product;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsClassificationReviewFixtures;
use Tests\TestCase;

class GiftClassificationReviewTest extends TestCase
{
    use BuildsClassificationReviewFixtures;
    use RefreshDatabase;

    public function test_list_tabs_filter_classification_queues(): void
    {
        $this->actingAs(User::factory()->create());

        $review = Product::factory()->create([
            'name' => 'Needs Taxonomy Review',
            'taxonomy_classification_status' => TaxonomyClassificationStatus::Review,
        ]);
        $accepted = Product::factory()->create([
            'name' => 'AI Accepted Gift',
            'taxonomy_classification_status' => TaxonomyClassificationStatus::AiAccepted,
        ]);
        $failed = Product::factory()->create([
            'name' => 'Failed Gift',
            'taxonomy_classification_status' => TaxonomyClassificationStatus::Failed,
        ]);

        Livewire::test(ListGifts::class)
            ->assertSee('Needs Review')
            ->assertSee('Failed')
            ->assertSee('AI Accepted')
            ->assertCanSeeTableRecords([$review, $accepted, $failed])
            ->set('activeTab', 'review')
            ->assertCanSeeTableRecords([$review])
            ->assertCanNotSeeTableRecords([$accepted, $failed])
            ->set('activeTab', 'failed')
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$review, $accepted]);
    }

    public function test_edit_page_shows_proposal_and_applied_distinction(): void
    {
        $this->actingAs(User::factory()->create());
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $product = $this->reviewProduct($home, [
            'reasoning_summary' => ['primary_category' => 'Kitchenware family.'],
        ]);

        Livewire::test(EditGift::class, [
            'record' => $product->getRouteKey(),
        ])
            ->assertOk()
            ->assertSee('AI proposal')
            ->assertSee('Applied taxonomy')
            ->assertSee('Why review is needed')
            ->assertSee('Needs review')
            ->assertSee('Low primary category confidence')
            ->assertSee('data-classification-review', false)
            ->assertSee('data-review-layout="full-width"', false)
            ->assertSee('data-classification-review-section', false)
            ->assertSee('Home & Living')
            ->assertSee('Trusted source hints')
            ->assertSee('AI proposed Relationships')
            ->assertSee('Kitchenware family.')
            ->assertSee('Classification')
            ->assertActionExists('approveClassification')
            ->assertActionExists('rejectClassification')
            ->assertActionExists('reclassify');
    }

    public function test_failed_edit_page_shows_failure_reasons_full_width(): void
    {
        $this->actingAs(User::factory()->create());
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $product = $this->reviewProduct($home, [
            'review_reasons' => ['missing_primary_category'],
            'primary_category_id' => null,
            'category_ids' => [],
        ], TaxonomyClassificationStatus::Failed);
        $product->taxonomy_review_reasons = ['missing_primary_category'];
        $product->taxonomy_gap_explanation = 'Curated enrichment did not produce a valid primary category.';
        $product->save();

        Livewire::test(EditGift::class, [
            'record' => $product->getRouteKey(),
        ])
            ->assertOk()
            ->assertSee('Why classification failed')
            ->assertSee('Missing primary category')
            ->assertSee('No stored proposal')
            ->assertSee('data-review-layout="full-width"', false)
            ->assertActionExists('reclassify');
    }

    public function test_approve_action_applies_proposal(): void
    {
        $this->actingAs(User::factory()->create());
        $fashion = $this->merchandisingCategory('Fashion & Accessories', 'fashion-and-accessories');
        $jewellery = $this->merchandisingCategory('Jewellery', 'jewellery', $fashion->id);
        $husband = $this->taxonomyValue(Relationship::class, 'Husband');
        $product = $this->reviewProduct($jewellery, [
            'relationship_ids' => [$husband->id],
            'confidence' => ['primary_category' => 0.94],
        ]);

        Livewire::test(EditGift::class, [
            'record' => $product->getRouteKey(),
        ])
            ->callAction('approveClassification')
            ->assertHasNoActionErrors();

        $product->refresh();
        $this->assertSame(TaxonomyClassificationStatus::HumanApproved, $product->taxonomy_classification_status);
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertTrue($product->categories()->where('categories.id', $jewellery->id)->wherePivot('is_primary', true)->exists());
        $this->assertTrue($product->categories()->where('categories.id', $fashion->id)->exists());
    }

    public function test_manual_taxonomy_save_overrides_without_touching_title_only_edits(): void
    {
        $this->actingAs(User::factory()->create());
        $electronics = $this->merchandisingCategory('Electronics', 'electronics');
        $home = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $husband = $this->taxonomyValue(Relationship::class, 'Husband');
        $product = $this->reviewProduct($electronics, [], TaxonomyClassificationStatus::AiAccepted);
        $product->categories()->attach($electronics->id, ['is_primary' => true]);
        $product->relationships()->attach($husband->id);
        $product->name = 'Keep This Title';
        $product->save();

        Livewire::test(EditGift::class, [
            'record' => $product->getRouteKey(),
        ])
            ->fillForm([
                'name' => 'Keep This Title',
                'slug' => $product->slug,
                'primary_category_id' => $electronics->id,
                'relationship_ids' => [$husband->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $product->fresh()->taxonomy_classification_status);

        Livewire::test(EditGift::class, [
            'record' => $product->getRouteKey(),
        ])
            ->fillForm([
                'name' => 'Keep This Title',
                'slug' => $product->slug,
                'primary_category_id' => $home->id,
                'relationship_ids' => [$husband->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(TaxonomyClassificationStatus::HumanOverridden, $product->fresh()->taxonomy_classification_status);
        $this->assertTrue($product->fresh()->categories()->where('categories.id', $home->id)->wherePivot('is_primary', true)->exists());
    }

    public function test_inactive_taxonomy_is_not_offered_in_selectors(): void
    {
        $this->actingAs(User::factory()->create());
        $active = $this->merchandisingCategory('Home & Living', 'home-and-living');
        $inactive = Category::query()->create([
            'name' => 'Retired Composite',
            'slug' => 'retired-composite',
            'is_active' => false,
        ]);
        $product = $this->reviewProduct($active);

        $html = Livewire::test(EditGift::class, [
            'record' => $product->getRouteKey(),
        ])->html();

        $this->assertStringContainsString('data-classification-review', $html);
        $this->assertStringNotContainsString('Retired Composite', $html);

        $options = app(LoadGiftTaxonomySelectOptionsAction::class)->execute()['categories'];
        $this->assertArrayHasKey($active->id, $options);
        $this->assertArrayNotHasKey($inactive->id, $options);
    }

    public function test_source_lists_page_is_read_only(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ListCatalogSourceLists::class)
            ->assertOk();
    }
}
