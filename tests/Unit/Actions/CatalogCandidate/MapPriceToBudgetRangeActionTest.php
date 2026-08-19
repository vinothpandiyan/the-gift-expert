<?php

namespace Tests\Unit\Actions\CatalogCandidate;

use App\Actions\CatalogCandidate\MapPriceToBudgetRangeAction;
use Database\Seeders\BudgetRangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapPriceToBudgetRangeActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BudgetRangeSeeder::class);
    }

    public function test_it_maps_inr_boundaries_using_existing_min_max_semantics(): void
    {
        $action = app(MapPriceToBudgetRangeAction::class);

        $this->assertSame('under-500', $action->execute('499.99', 'INR')?->slug);
        $this->assertSame('500-1000', $action->execute('500.00', 'INR')?->slug);
        $this->assertSame('500-1000', $action->execute('1000.00', 'INR')?->slug);
        $this->assertSame('1000-2500', $action->execute('1000.01', 'INR')?->slug);
        $this->assertSame('5000-10000', $action->execute('10000.00', 'INR')?->slug);
        $this->assertSame('10000-plus', $action->execute('10000.01', 'INR')?->slug);
    }

    public function test_it_returns_null_for_missing_or_mismatched_currency(): void
    {
        $action = app(MapPriceToBudgetRangeAction::class);

        $this->assertNull($action->execute(null, 'INR'));
        $this->assertNull($action->execute('750.00', 'USD'));
        $this->assertNull($action->execute('not-a-price', 'INR'));
    }
}
