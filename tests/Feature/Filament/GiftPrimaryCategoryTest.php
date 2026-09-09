<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Gifts\Pages\EditGift;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GiftPrimaryCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_primary_category_normalizes_ancestors_and_keeps_a_single_primary(): void
    {
        $this->actingAs(User::factory()->create());

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

        $product = Product::factory()->create();

        Livewire::test(EditGift::class, [
            'record' => $product->getRouteKey(),
        ])
            ->fillForm([
                'name' => $product->name,
                'slug' => $product->slug,
                'primary_category_id' => $jewellery->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $primaries = $product->categories()->wherePivot('is_primary', true)->pluck('categories.id');

        $this->assertCount(1, $primaries);
        $this->assertTrue($primaries->contains($jewellery->id));
        $this->assertTrue($product->categories()->where('categories.id', $fashion->id)->exists());
        $this->assertFalse(
            (bool) $product->categories()->where('categories.id', $fashion->id)->first()?->pivot->is_primary,
        );
    }
}
