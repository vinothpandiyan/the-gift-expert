<?php

namespace Tests\Unit\Actions\Product;

use App\Actions\Product\ApplyProductTaxonomyClassificationAction;
use App\CommercialSourcing\ValidatedProductTaxonomyClassification;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyProductTaxonomyClassificationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_taxonomy_pivots_for_draft_products(): void
    {
        $product = Product::factory()->create(['status' => ProductStatus::Draft]);
        $primary = Category::query()->create([
            'name' => 'Home',
            'slug' => 'home',
            'is_active' => true,
        ]);
        $secondary = Category::query()->create([
            'name' => 'Kitchen',
            'slug' => 'kitchen',
            'is_active' => true,
        ]);
        $occasion = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);
        $relationship = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);
        $giftType = GiftType::query()->create([
            'name' => 'Practical',
            'slug' => 'practical',
            'is_active' => true,
        ]);

        $applied = app(ApplyProductTaxonomyClassificationAction::class)->execute(
            $product,
            new ValidatedProductTaxonomyClassification(
                primaryCategoryId: $primary->id,
                categoryIds: [$primary->id, $secondary->id],
                occasionIds: [$occasion->id],
                relationshipIds: [$relationship->id],
                recipientTypeIds: [],
                interestIds: [],
                professionIds: [],
                giftTypeIds: [$giftType->id],
                exceptionCodes: [],
                rejectedIds: [],
            ),
        );

        $this->assertTrue($applied);
        $this->assertSame([$primary->id, $secondary->id], $product->categories()->pluck('categories.id')->all());
        $this->assertTrue((bool) $product->categories()->where('categories.id', $primary->id)->first()->pivot->is_primary);
        $this->assertFalse((bool) $product->categories()->where('categories.id', $secondary->id)->first()->pivot->is_primary);
        $this->assertSame([$occasion->id], $product->occasions()->pluck('occasions.id')->all());
        $this->assertSame([$relationship->id], $product->relationships()->pluck('relationships.id')->all());
        $this->assertSame([$giftType->id], $product->giftTypes()->pluck('gift_types.id')->all());
    }

    public function test_it_is_idempotent_when_called_twice(): void
    {
        $product = Product::factory()->create(['status' => ProductStatus::Draft]);
        $category = Category::query()->create([
            'name' => 'Home',
            'slug' => 'home',
            'is_active' => true,
        ]);
        $classification = new ValidatedProductTaxonomyClassification(
            primaryCategoryId: $category->id,
            categoryIds: [$category->id],
            occasionIds: [],
            relationshipIds: [],
            recipientTypeIds: [],
            interestIds: [],
            professionIds: [],
            giftTypeIds: [],
            exceptionCodes: [],
            rejectedIds: [],
        );
        $action = app(ApplyProductTaxonomyClassificationAction::class);

        $action->execute($product, $classification);
        $action->execute($product->fresh(), $classification);

        $this->assertSame(1, $product->categories()->count());
    }

    public function test_it_does_not_mutate_published_product_taxonomy(): void
    {
        $product = Product::factory()->create(['status' => ProductStatus::Published]);
        $existing = Category::query()->create([
            'name' => 'Existing',
            'slug' => 'existing',
            'is_active' => true,
        ]);
        $replacement = Category::query()->create([
            'name' => 'Replacement',
            'slug' => 'replacement',
            'is_active' => true,
        ]);
        $product->categories()->sync([
            $existing->id => ['is_primary' => true],
        ]);

        $applied = app(ApplyProductTaxonomyClassificationAction::class)->execute(
            $product,
            new ValidatedProductTaxonomyClassification(
                primaryCategoryId: $replacement->id,
                categoryIds: [$replacement->id],
                occasionIds: [],
                relationshipIds: [],
                recipientTypeIds: [],
                interestIds: [],
                professionIds: [],
                giftTypeIds: [],
                exceptionCodes: [],
                rejectedIds: [],
            ),
        );

        $this->assertFalse($applied);
        $this->assertSame([$existing->id], $product->fresh()->categories()->pluck('categories.id')->all());
    }

    public function test_it_does_not_mutate_archived_product_taxonomy(): void
    {
        $product = Product::factory()->create(['status' => ProductStatus::Archived]);
        $existing = Category::query()->create([
            'name' => 'Existing',
            'slug' => 'existing-archived',
            'is_active' => true,
        ]);
        $replacement = Category::query()->create([
            'name' => 'Replacement',
            'slug' => 'replacement-archived',
            'is_active' => true,
        ]);
        $product->categories()->sync([
            $existing->id => ['is_primary' => true],
        ]);

        $applied = app(ApplyProductTaxonomyClassificationAction::class)->execute(
            $product,
            new ValidatedProductTaxonomyClassification(
                primaryCategoryId: $replacement->id,
                categoryIds: [$replacement->id],
                occasionIds: [],
                relationshipIds: [],
                recipientTypeIds: [],
                interestIds: [],
                professionIds: [],
                giftTypeIds: [],
                exceptionCodes: [],
                rejectedIds: [],
            ),
        );

        $this->assertFalse($applied);
        $this->assertSame([$existing->id], $product->fresh()->categories()->pluck('categories.id')->all());
    }
}
