<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Gifts\Pages\EditGift;
use App\Filament\Resources\Gifts\RelationManagers\CategoriesRelationManager;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GiftPrimaryCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_primary_category_remains_after_attach(): void
    {
        $this->actingAs(User::factory()->create());

        $product = Product::factory()->create();

        $first = Category::query()->create([
            'name' => 'First',
            'slug' => 'first',
            'full_path' => 'first',
        ]);

        $second = Category::query()->create([
            'name' => 'Second',
            'slug' => 'second',
            'full_path' => 'second',
        ]);

        $product->categories()->attach($first->id, ['is_primary' => true]);

        Livewire::test(CategoriesRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditGift::class,
        ])
            ->callTableAction('attach', data: [
                'recordId' => $second->id,
                'is_primary' => true,
            ])
            ->assertHasNoTableActionErrors();

        $primaries = $product->categories()->wherePivot('is_primary', true)->pluck('categories.id');

        $this->assertCount(1, $primaries);
        $this->assertTrue($primaries->contains($second->id));
    }
}
