<?php

namespace Tests\Unit\Actions\Product;

use App\Actions\Product\BackfillLegacyPublishedProductTaxonomyClassificationAction;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillLegacyPublishedProductTaxonomyClassificationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_products_with_primary_category_become_human_approved(): void
    {
        $category = Category::query()->create([
            'name' => 'Home',
            'slug' => 'home',
            'is_active' => true,
        ]);

        $published = Product::factory()->published()->create([
            'taxonomy_classification_status' => TaxonomyClassificationStatus::None,
        ]);
        $published->categories()->attach($category->id, ['is_primary' => true]);

        $draft = Product::factory()->create([
            'taxonomy_classification_status' => TaxonomyClassificationStatus::None,
        ]);
        $draft->categories()->attach($category->id, ['is_primary' => true]);

        $publishedWithoutCategory = Product::factory()->published()->create([
            'taxonomy_classification_status' => TaxonomyClassificationStatus::None,
        ]);

        $count = app(BackfillLegacyPublishedProductTaxonomyClassificationAction::class)->execute();

        $this->assertSame(1, $count);
        $this->assertSame(TaxonomyClassificationStatus::HumanApproved, $published->fresh()->taxonomy_classification_status);
        $this->assertNotNull($published->fresh()->taxonomy_approved_at);
        $this->assertSame(TaxonomyClassificationStatus::None, $draft->fresh()->taxonomy_classification_status);
        $this->assertSame(TaxonomyClassificationStatus::None, $publishedWithoutCategory->fresh()->taxonomy_classification_status);
        $this->assertSame(ProductStatus::Published, $published->fresh()->status);
    }
}
