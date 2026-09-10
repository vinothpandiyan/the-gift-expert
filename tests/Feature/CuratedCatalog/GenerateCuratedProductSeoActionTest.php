<?php

namespace Tests\Feature\CuratedCatalog;

use App\Actions\CuratedCatalog\GenerateCuratedProductSeoAction;
use App\Actions\Product\MarkProductSeoAsHumanOwnedAction;
use App\CommercialSourcing\CommercialEnrichmentException;
use App\Enums\ProductStatus;
use App\Enums\SeoOwnership;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\TestCase;

class GenerateCuratedProductSeoActionTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use RefreshDatabase;

    public function test_it_generates_seo_from_editorial_product_data_without_publishing(): void
    {
        $this->configureCuratedAmazonMerchant();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'meta_title' => 'Personalized Night Light for Best Friends',
                            'meta_description' => 'Turn a favourite photo into a warm acrylic keepsake designed for birthdays, farewells and meaningful friendship moments.',
                        ]),
                    ],
                ]],
            ]),
        ]);
        $product = Product::factory()->draft()->create([
            'name' => 'Personalized Best Friend Acrylic Night Light',
            'short_description' => 'A custom photo night light with a wooden base.',
            'description' => 'Turns a favourite photo into a lasting keepsake.',
        ]);

        $result = app(GenerateCuratedProductSeoAction::class)->execute($product);
        $fresh = $product->fresh();

        $this->assertTrue($result['changed']);
        $this->assertSame('Personalized Night Light for Best Friends', $fresh->meta_title);
        $this->assertSame(SeoOwnership::Ai, $fresh->seo_ownership);
        $this->assertSame(1, $fresh->seo_generation_version);
        $this->assertSame(ProductStatus::Draft, $fresh->status);
        $this->assertNull($fresh->published_at);
    }

    public function test_human_owned_seo_cannot_be_regenerated(): void
    {
        $product = Product::factory()->draft()->create([
            'name' => 'Human SEO Gift',
            'short_description' => 'Clean editorial copy.',
            'meta_title' => 'Carefully Written Human SEO',
            'meta_description' => 'A carefully reviewed description.',
        ]);
        app(MarkProductSeoAsHumanOwnedAction::class)->execute($product);
        Http::fake();

        $this->expectException(CommercialEnrichmentException::class);

        app(GenerateCuratedProductSeoAction::class)->execute($product);
    }

    public function test_command_dry_run_selects_without_calls_or_writes(): void
    {
        $product = Product::factory()->draft()->create([
            'name' => 'Dry Run SEO Gift',
            'short_description' => 'Clean editorial copy for a dry run.',
        ]);
        Http::fake();

        $this->artisan('catalog:generate-product-seo', [
            '--product' => (string) $product->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run completed')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull($product->fresh()->meta_title);
    }
}
