<?php

namespace Tests\Unit\Actions\Catalog;

use App\Actions\Catalog\AnalyzeCatalogCoverageAction;
use App\CatalogCoverage\CatalogCoverageOptions;
use App\CatalogCoverage\CatalogCoverageReport;
use App\Enums\CoverageGapSeverity;
use App\Enums\ProductAutomationReadiness;
use App\Enums\ProductStatus;
use App\Models\CatalogCandidateSourcingItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Relationship;
use Database\Seeders\BudgetRangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyzeCatalogCoverageActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BudgetRangeSeeder::class);
    }

    public function test_default_population_includes_published_manual_and_automation_drafts_excludes_manual_drafts(): void
    {
        $publishedManual = Product::factory()->published()->create(['slug' => 'published-manual']);
        $automationDraft = Product::factory()->draft()->create(['slug' => 'automation-draft']);
        $manualDraft = Product::factory()->draft()->create(['slug' => 'manual-draft']);
        $archived = Product::factory()->published()->create([
            'slug' => 'archived-product',
            'status' => ProductStatus::Archived,
        ]);

        $this->linkAutomationDraft($automationDraft);

        $report = $this->analyze();

        $this->assertSame(2, $report->totalProducts);
        $this->assertSame(1, $report->publishedProducts);
        $this->assertSame(1, $report->automationDraftProducts);
        $this->assertSame(ProductStatus::Archived, Product::query()->find($archived->id)?->status);
    }

    public function test_published_only_option_excludes_automation_drafts(): void
    {
        $published = Product::factory()->published()->create(['slug' => 'published-only']);
        $automationDraft = Product::factory()->draft()->create(['slug' => 'draft-only']);
        $this->linkAutomationDraft($automationDraft);

        $report = $this->analyze(new CatalogCoverageOptions(publishedOnly: true));

        $this->assertSame(1, $report->totalProducts);
        $this->assertSame(1, $report->publishedProducts);
        $this->assertSame(0, $report->automationDraftProducts);
        $this->assertSame($published->id, $report->totalProducts > 0 ? $published->id : null);
    }

    public function test_include_manual_drafts_option_includes_unlinked_drafts(): void
    {
        Product::factory()->published()->create(['slug' => 'published']);
        Product::factory()->draft()->create(['slug' => 'manual-draft']);

        $report = $this->analyze(new CatalogCoverageOptions(includeManualDrafts: true));

        $this->assertSame(2, $report->totalProducts);
    }

    public function test_budget_mapping_uses_existing_boundary_tie_break_semantics(): void
    {
        Product::factory()->published()->create([
            'slug' => 'under-500',
            'price_amount' => '499.99',
            'price_currency' => 'INR',
        ]);
        Product::factory()->published()->create([
            'slug' => 'five-hundred',
            'price_amount' => '500.00',
            'price_currency' => 'INR',
        ]);
        Product::factory()->published()->create([
            'slug' => 'one-thousand',
            'price_amount' => '1000.00',
            'price_currency' => 'INR',
        ]);
        Product::factory()->published()->create([
            'slug' => 'ten-thousand',
            'price_amount' => '10000.00',
            'price_currency' => 'INR',
        ]);

        $report = $this->analyze();

        $bySlug = collect($report->budgetCoverage)->keyBy('slug');

        $this->assertSame(1, $bySlug['under-500']->productCount);
        $this->assertSame(2, $bySlug['500-1000']->productCount);
        $this->assertSame(1, $bySlug['5000-10000']->productCount);
    }

    public function test_unpriced_products_are_counted_separately_and_excluded_from_budget_buckets(): void
    {
        Product::factory()->published()->create([
            'slug' => 'priced',
            'price_amount' => '750.00',
            'price_currency' => 'INR',
        ]);
        Product::factory()->published()->create([
            'slug' => 'unpriced',
            'price_amount' => null,
            'price_currency' => 'INR',
        ]);

        $report = $this->analyze();

        $this->assertSame(2, $report->totalProducts);
        $this->assertSame(1, $report->unpricedCount);
        $this->assertSame(1, collect($report->budgetCoverage)->sum('productCount'));
    }

    public function test_optional_budget_targets_create_delta_and_thin_severity(): void
    {
        config([
            'catalog_coverage.budget_target_percentages' => [
                '500-1000' => 90,
            ],
        ]);

        Product::factory()->published()->create([
            'slug' => 'priced-one',
            'price_amount' => '750.00',
            'price_currency' => 'INR',
        ]);
        Product::factory()->published()->create([
            'slug' => 'priced-two',
            'price_amount' => '800.00',
            'price_currency' => 'INR',
        ]);

        $report = $this->analyze();
        $row = collect($report->budgetCoverage)->firstWhere('slug', '500-1000');

        $this->assertNotNull($row);
        $this->assertSame(90.0, $row->targetSharePercent);
        $this->assertSame(10.0, $row->deltaFromTargetPercent);
        $this->assertSame(CoverageGapSeverity::Thin, $row->severity);
    }

    public function test_dimension_counts_use_distinct_products_without_pivot_inflation(): void
    {
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);

        $product = Product::factory()->published()->create(['slug' => 'multi-tag']);
        $product->relationships()->attach($husband);

        $report = $this->analyze();
        $row = collect($report->dimensionCoverage)->first(
            fn ($coverage) => $coverage->dimension === 'relationship' && $coverage->slug === 'husband',
        );

        $this->assertNotNull($row);
        $this->assertSame(1, $row->productCount);
    }

    public function test_composite_relationship_budget_counts_matching_products(): void
    {
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);

        $product = Product::factory()->published()->create([
            'slug' => 'husband-budget',
            'price_amount' => '750.00',
            'price_currency' => 'INR',
        ]);
        $product->relationships()->attach($husband);

        $report = $this->analyze();
        $cell = collect($report->compositeCoverage)->first(
            fn ($coverage) => $coverage->compositeKey === 'relationship_budget'
                && ($coverage->dimensionValues['relationship'] ?? null) === 'husband'
                && ($coverage->dimensionValues['budget_range'] ?? null) === '500-1000',
        );

        $this->assertNotNull($cell);
        $this->assertSame(1, $cell->productCount);
    }

    public function test_budget_maps_simple_published_product(): void
    {
        Product::factory()->published()->create([
            'slug' => 'simple-budget-product',
            'price_amount' => '750.00',
            'price_currency' => 'INR',
        ]);

        $report = $this->analyze();

        $this->assertSame(1, collect($report->budgetCoverage)->firstWhere('slug', '500-1000')?->productCount);
    }

    public function test_category_budget_composite_uses_primary_category_only(): void
    {
        $primary = Category::query()->create([
            'name' => 'Primary Cat',
            'slug' => 'primary-cat',
            'full_path' => 'primary-cat',
            'is_active' => true,
        ]);
        $secondary = Category::query()->create([
            'name' => 'Secondary Cat',
            'slug' => 'secondary-cat',
            'full_path' => 'secondary-cat',
            'is_active' => true,
        ]);

        $product = Product::factory()->published()->create([
            'slug' => 'primary-budget',
            'price_amount' => '750.00',
            'price_currency' => 'INR',
        ]);
        $product->categories()->sync([
            $primary->id => ['is_primary' => true],
            $secondary->id => ['is_primary' => false],
        ]);

        $this->assertTrue($product->categories()->wherePivot('is_primary', true)->exists());

        $report = $this->analyze();

        $primaryCell = collect($report->compositeCoverage)->first(
            fn ($coverage) => $coverage->compositeKey === 'category_budget'
                && ($coverage->dimensionValues['category'] ?? null) === 'primary-cat'
                && ($coverage->dimensionValues['budget_range'] ?? null) === '500-1000',
        );
        $secondaryCell = collect($report->compositeCoverage)->first(
            fn ($coverage) => $coverage->compositeKey === 'category_budget'
                && ($coverage->dimensionValues['category'] ?? null) === 'secondary-cat'
                && ($coverage->dimensionValues['budget_range'] ?? null) === '500-1000',
        );

        $this->assertNotNull($primaryCell);
        $this->assertSame(1, $primaryCell->productCount);
        $this->assertNotNull($secondaryCell);
        $this->assertSame(0, $secondaryCell->productCount);
    }

    public function test_gaps_mark_empty_and_thin_buckets(): void
    {
        $husband = Relationship::query()->create([
            'name' => 'Husband',
            'slug' => 'husband',
            'is_active' => true,
        ]);

        Product::factory()->published()->create([
            'slug' => 'single-product',
            'price_amount' => '750.00',
            'price_currency' => 'INR',
        ])->relationships()->attach($husband);

        $report = $this->analyze();

        $this->assertTrue(collect($report->gaps)->contains(
            fn ($gap) => $gap->scope === 'dimension'
                && $gap->severity === CoverageGapSeverity::Thin
                && str_contains($gap->label, 'Husband'),
        ));
    }

    public function test_missing_taxonomy_summary_reports_all_dimensions(): void
    {
        Product::factory()->published()->create([
            'slug' => 'bare-product',
            'price_amount' => '750.00',
            'price_currency' => 'INR',
        ]);

        $report = $this->analyze();

        $this->assertSame(1, $report->missingTaxonomy->noPrimaryCategory);
        $this->assertSame(1, $report->missingTaxonomy->noCategory);
        $this->assertSame(1, $report->missingTaxonomy->noRelationship);
        $this->assertSame(1, $report->missingTaxonomy->noProfession);
    }

    public function test_optional_taxonomy_absence_is_not_a_gap_by_default(): void
    {
        Product::factory()->published()->create([
            'slug' => 'no-profession',
            'price_amount' => '750.00',
            'price_currency' => 'INR',
        ]);

        $report = $this->analyze();

        $this->assertSame(1, $report->missingTaxonomy->noProfession);
        $this->assertFalse(collect($report->gaps)->contains(
            fn ($gap) => str_contains($gap->label, 'profession'),
        ));
    }

    public function test_readiness_summary_counts_latest_automation_draft_items(): void
    {
        $readyDraft = Product::factory()->draft()->create(['slug' => 'ready-draft']);
        $blockedDraft = Product::factory()->draft()->create(['slug' => 'blocked-draft']);

        $this->linkAutomationDraft($readyDraft, ProductAutomationReadiness::Ready);
        $this->linkAutomationDraft($blockedDraft, ProductAutomationReadiness::Blocked);

        $report = $this->analyze();

        $this->assertSame(2, $report->automationDraftProducts);
        $this->assertSame(1, $report->readiness->ready);
        $this->assertSame(1, $report->readiness->blocked);
    }

    public function test_execute_performs_zero_database_writes(): void
    {
        Product::factory()->published()->create([
            'slug' => 'stable-product',
            'price_amount' => '750.00',
            'price_currency' => 'INR',
        ]);

        $tables = ['products', 'catalog_candidate_sourcing_items', 'relationship_product'];

        $before = [];

        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $this->analyze();

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "Unexpected writes to {$table}");
        }
    }

    private function analyze(CatalogCoverageOptions $options = new CatalogCoverageOptions): CatalogCoverageReport
    {
        return app(AnalyzeCatalogCoverageAction::class)->execute($options);
    }

    private function linkAutomationDraft(Product $product, ?ProductAutomationReadiness $readiness = null): CatalogCandidateSourcingItem
    {
        return CatalogCandidateSourcingItem::factory()->create([
            'product_id' => $product->id,
            'readiness' => $readiness,
        ]);
    }
}
