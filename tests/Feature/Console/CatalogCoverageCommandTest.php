<?php

namespace Tests\Feature\Console;

use App\Models\Product;
use Database\Seeders\BudgetRangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CatalogCoverageCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BudgetRangeSeeder::class);
    }

    public function test_command_prints_human_summary(): void
    {
        Product::factory()->published()->create([
            'slug' => 'coverage-product',
            'price_amount' => '750.00',
            'price_currency' => 'INR',
        ]);

        $this->artisan('catalog:coverage')
            ->assertSuccessful()
            ->expectsOutputToContain('Catalog Coverage')
            ->expectsOutputToContain('Total: 1')
            ->expectsOutputToContain('Published: 1')
            ->expectsOutputToContain('Budget');
    }

    public function test_command_json_output_includes_structured_fields(): void
    {
        Product::factory()->published()->create([
            'slug' => 'json-product',
            'price_amount' => '750.00',
            'price_currency' => 'INR',
        ]);

        $exitCode = Artisan::call('catalog:coverage', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"total_products": 1', $output);
        $this->assertStringContainsString('budget_coverage', $output);
        $this->assertStringContainsString('500-1000', $output);
    }
}
