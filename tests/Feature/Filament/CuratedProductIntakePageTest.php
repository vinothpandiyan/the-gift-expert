<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CuratedProductIntake;
use App\Filament\Resources\Gifts\GiftResource;
use App\Models\Category;
use App\Models\CuratedProductIntakeRun;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\Support\FakesCommercialEnrichment;
use Tests\TestCase;

class CuratedProductIntakePageTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use FakesCommercialEnrichment;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureCuratedAmazonMerchant();
        Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
    }

    public function test_page_loads_preview_and_confirm_without_writes_during_preview(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create());

        Livewire::test(CuratedProductIntake::class)
            ->assertOk()
            ->set('data.merchant_slug', 'amazon-in')
            ->set('data.payload', $this->curatedPayload())
            ->call('previewImport')
            ->assertSet('previewSummary.items_new', 1)
            ->assertSet('previewSummary.items_with_warnings', 0)
            ->assertSet('commitResult', null);

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, CuratedProductIntakeRun::query()->count());
        Http::assertNothingSent();
    }

    public function test_preview_hydrates_summary_cards_and_source_image_url(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create());

        $component = Livewire::test(CuratedProductIntake::class)
            ->set('data.merchant_slug', 'amazon-in')
            ->set('data.payload', $this->curatedPayload())
            ->call('previewImport')
            ->assertSet('previewSummary.items_total', 1)
            ->assertSet('previewSummary.items_actionable_this_commit', 1)
            ->assertSet('previewRows.0.source_image_url', 'https://m.media-amazon.com/images/I/example.jpg')
            ->assertSet('previewRows.0.affiliate_ready', true)
            ->assertSet('previewRows.0.price_display', '₹1,299')
            ->assertSet('previewRows.0.availability_label', 'In stock')
            ->assertSet('previewRows.0.disposition_label', 'NEW')
            ->assertSet('previewRows.0.action_label', 'Create')
            ->assertSee('Preview summary')
            ->assertSee('Total')
            ->assertSee('Ready')
            ->assertSee('₹1,299')
            ->assertSee('In stock')
            ->assertSee('NEW')
            ->assertSee('Create')
            ->assertSee('Ready');

        $html = $component->html();

        $this->assertSame(1, substr_count($html, 'data-preview-asin="B0ABCDEFGH"'));
        $this->assertSame(1, substr_count($html, 'Stainless Steel French Press'));
        $this->assertSame(1, substr_count($html, 'data-preview-summary'));
        $this->assertStringContainsString('data-summary-card="total"', $html);
        $this->assertStringContainsString('data-summary-card="ready"', $html);
        $this->assertStringContainsString('Amazon source images are preview-only.', $html);
    }

    public function test_preview_decodes_html_entities_in_title(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create());

        Livewire::test(CuratedProductIntake::class)
            ->set('data.merchant_slug', 'amazon-in')
            ->set('data.payload', $this->curatedPayload([
                'items' => [[
                    'external_product_id' => 'B0ENTITY01',
                    'source_url' => 'https://www.amazon.in/dp/B0ENTITY01',
                    'title' => 'WildHorn Maroon Leather Men&#039;s Wallet &amp; Belt Combo',
                    'price_amount' => '949.00',
                    'price_currency' => 'INR',
                ]],
            ]))
            ->call('previewImport')
            ->assertSet('previewRows.0.title', "WildHorn Maroon Leather Men's Wallet & Belt Combo")
            ->assertSet('previewRows.0.price_display', '₹949')
            ->assertSee("WildHorn Maroon Leather Men's Wallet & Belt Combo")
            ->assertDontSee('Men&amp;#039;s', false)
            ->assertDontSee('&amp;amp;', false);
    }

    public function test_preview_renders_human_readable_warning_chips(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create());

        Livewire::test(CuratedProductIntake::class)
            ->set('data.merchant_slug', 'amazon-in')
            ->set('data.payload', $this->curatedPayload([
                'items' => [[
                    'external_product_id' => 'B0WARNING1',
                    'source_url' => 'https://www.amazon.in/dp/B0WARNING1',
                    'title' => 'Warning Fixture',
                    'price_amount' => '299.00',
                    'price_currency' => 'INR',
                    'availability' => 'unknown',
                ]],
            ]))
            ->call('previewImport')
            ->assertSee('₹299')
            ->assertSee('Unknown')
            ->assertSee('Missing image');
    }

    public function test_confirm_import_creates_audit_summary_with_gift_edit_link_data(): void
    {
        $home = Category::query()->where('slug', 'home-and-living')->firstOrFail();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                        'relationship_ids' => [],
                        'occasion_ids' => [],
                    ],
                ]),
            ),
        ]);

        $this->actingAs(User::factory()->create());

        $component = Livewire::test(CuratedProductIntake::class)
            ->set('data.merchant_slug', 'amazon-in')
            ->set('data.payload', $this->curatedPayload())
            ->call('confirmImport')
            ->assertSet('commitResult.items_created', 1);

        $product = Product::query()->firstOrFail();
        $processed = $component->get('commitResult')['processed_items'][0];

        $this->assertSame('created', $processed['outcome']);
        $this->assertSame($product->id, $processed['product_id']);
        $this->assertSame($product->name, $processed['product_name']);
        $this->assertTrue($processed['affiliate_ready']);
        $this->assertContains('missing_relationships', $processed['warnings']);
        $this->assertContains('missing_occasions', $processed['warnings']);

        $this->assertSame(
            GiftResource::getUrl('edit', ['record' => $product->id]),
            GiftResource::getUrl('edit', ['record' => $processed['product_id']]),
        );

        $html = $component->html();

        $this->assertStringContainsString('data-result-summary', $html);
        $this->assertStringContainsString('data-result-card="created"', $html);
        $this->assertStringContainsString('data-result-card="updated"', $html);
        $this->assertStringContainsString('data-result-card="skipped"', $html);
        $this->assertStringContainsString('data-result-card="failed"', $html);
        $this->assertStringContainsString('data-outcome-group="created"', $html);
        $this->assertStringNotContainsString('data-outcome-group="updated"', $html);
        $this->assertStringNotContainsString('data-outcome-group="skipped"', $html);
        $this->assertStringNotContainsString('data-outcome-group="failed"', $html);
        $this->assertSame(1, substr_count($html, 'data-edit-gift-link'));
        $this->assertStringContainsString('No relationships', $html);
        $this->assertStringContainsString('No occasions', $html);

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, CuratedProductIntakeRun::query()->count());
    }

    public function test_preview_preserves_long_title_in_rows(): void
    {
        Http::fake();

        $title = 'Extra Long Marketplace Title '.str_repeat('Keyword ', 20);

        $this->actingAs(User::factory()->create());

        $component = Livewire::test(CuratedProductIntake::class)
            ->set('data.merchant_slug', 'amazon-in')
            ->set('data.payload', $this->curatedPayload([
                'items' => [[
                    'external_product_id' => 'B0LONGTITL',
                    'source_url' => 'https://www.amazon.in/dp/B0LONGTITL',
                    'title' => $title,
                    'price_amount' => '499.00',
                    'price_currency' => 'INR',
                ]],
            ]))
            ->call('previewImport');

        $this->assertStringContainsString('Extra Long Marketplace Title', $component->get('previewRows.0.title'));
    }
}
