<?php

namespace Tests\Unit\Models;

use App\Models\Product;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxonomyInversePivotTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_products_uses_relationship_product_pivot(): void
    {
        $relationship = Relationship::query()->create([
            'name' => 'Partner',
            'slug' => 'partner',
        ]);

        $product = Product::factory()->create();

        $relationship->products()->attach($product);

        $this->assertDatabaseHas('relationship_product', [
            'relationship_id' => $relationship->id,
            'product_id' => $product->id,
        ]);

        $this->assertTrue($relationship->products()->whereKey($product->id)->exists());
    }

    public function test_recipient_type_products_uses_recipient_type_product_pivot(): void
    {
        $recipientType = RecipientType::query()->create([
            'name' => 'Him',
            'slug' => 'him',
        ]);

        $product = Product::factory()->create();

        $recipientType->products()->attach($product);

        $this->assertDatabaseHas('recipient_type_product', [
            'recipient_type_id' => $recipientType->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_profession_products_uses_profession_product_pivot(): void
    {
        $profession = Profession::query()->create([
            'name' => 'Engineer',
            'slug' => 'engineer',
        ]);

        $product = Product::factory()->create();

        $profession->products()->attach($product);

        $this->assertDatabaseHas('profession_product', [
            'profession_id' => $profession->id,
            'product_id' => $product->id,
        ]);
    }
}
