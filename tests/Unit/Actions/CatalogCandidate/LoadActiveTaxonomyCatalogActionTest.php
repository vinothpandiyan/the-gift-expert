<?php

namespace Tests\Unit\Actions\CatalogCandidate;

use App\Actions\CatalogCandidate\LoadActiveTaxonomyCatalogAction;
use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\Occasion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoadActiveTaxonomyCatalogActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_loads_active_taxonomy_and_omits_budget_ranges(): void
    {
        Category::query()->create(['name' => 'Home & Living', 'slug' => 'home-and-living', 'is_active' => true]);
        Category::query()->create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);
        Occasion::query()->create(['name' => 'Birthday', 'slug' => 'birthday', 'is_active' => true, 'sort_order' => 1]);
        BudgetRange::query()->create([
            'name' => 'Under ₹500',
            'slug' => 'under-500',
            'min_amount' => null,
            'max_amount' => '499.99',
            'currency' => 'INR',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $catalog = app(LoadActiveTaxonomyCatalogAction::class)->execute();
        $prompt = $catalog->toPromptArray();

        $this->assertCount(1, $catalog->categories);
        $this->assertSame('home-and-living', $catalog->categories[0]['slug']);
        $this->assertCount(1, $catalog->occasions);
        $this->assertArrayNotHasKey('budget_ranges', $prompt);
    }
}
