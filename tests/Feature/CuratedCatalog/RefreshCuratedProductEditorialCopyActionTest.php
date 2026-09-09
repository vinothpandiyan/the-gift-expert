<?php

namespace Tests\Feature\CuratedCatalog;

use App\Actions\CuratedCatalog\RefreshCuratedProductEditorialCopyAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\EditorialOwnership;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\TestCase;

class RefreshCuratedProductEditorialCopyActionTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use RefreshDatabase;

    public function test_it_rewrites_editorial_copy_without_reclassifying_or_publishing(): void
    {
        $this->configureCuratedAmazonMerchant();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'name' => 'Personalized Best Friend Acrylic Night Light',
                            'short_description' => 'Personalized acrylic night light with a custom photo and wooden base, designed as a keepsake for a best friend.',
                            'description' => "Turns a favorite photo into a lasting keepsake\nPersonal and meaningful without feeling generic\nGreat for birthdays, farewells and graduation",
                        ]),
                    ],
                ]],
            ]),
        ]);

        $merchant = Merchant::query()->where('slug', 'amazon-in')->firstOrFail();
        $product = Product::factory()->draft()->create([
            'name' => 'Giftplease Personalized Best Friend Acrylic Night Light',
            'short_description' => 'A lamp.',
            'description' => 'A thoughtful paragraph about friendship.',
            'taxonomy_classification_status' => TaxonomyClassificationStatus::AiAccepted,
            'taxonomy_content_fingerprint' => 'stable-fingerprint',
            'taxonomy_relationship_hint_fingerprint' => 'stable-hints',
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/B0NIGHT001?tag=test-tag-20',
            'external_product_id' => 'B0NIGHT001',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);

        $result = app(RefreshCuratedProductEditorialCopyAction::class)->execute($product);
        $fresh = $product->fresh();

        $this->assertTrue($result['changed']);
        $this->assertSame('Personalized Best Friend Acrylic Night Light', $fresh->name);
        $this->assertStringContainsString('Turns a favorite photo into a lasting keepsake', $fresh->description);
        $this->assertSame(EditorialOwnership::Ai, $fresh->editorial_ownership);
        $this->assertSame(1, $fresh->editorial_generation_version);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $fresh->taxonomy_classification_status);
        $this->assertSame('stable-fingerprint', $fresh->taxonomy_content_fingerprint);
        $this->assertSame('stable-hints', $fresh->taxonomy_relationship_hint_fingerprint);
        $this->assertSame(ProductStatus::Draft, $fresh->status);
        $this->assertNull($fresh->published_at);
    }

    public function test_command_dry_run_does_not_call_ai(): void
    {
        $this->configureCuratedAmazonMerchant();
        Http::fake();

        $merchant = Merchant::query()->where('slug', 'amazon-in')->firstOrFail();
        $product = Product::factory()->draft()->create([
            'name' => 'Noisy Marketplace Title Extra Words',
            'taxonomy_classification_status' => TaxonomyClassificationStatus::Review,
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/B0DRYRUN01?tag=test-tag-20',
            'external_product_id' => 'B0DRYRUN01',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);

        $this->artisan('catalog:refresh-curated-editorial', ['--product' => (string) $product->id, '--dry-run' => true])
            ->expectsOutputToContain('Eligible draft products: 1')
            ->expectsOutputToContain('Dry run completed')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('Noisy Marketplace Title Extra Words', $product->fresh()->name);
    }

    public function test_command_excludes_human_owned_editorial_copy(): void
    {
        $this->configureCuratedAmazonMerchant();
        Http::fake();

        $product = Product::factory()->draft()->create([
            'name' => 'Human Reviewed Gift',
            'editorial_ownership' => EditorialOwnership::Human,
            'editorial_reviewed_at' => now(),
        ]);

        $this->artisan('catalog:refresh-curated-editorial', [
            '--product' => (string) $product->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Excluded: human_owned')
            ->expectsOutputToContain('Eligible draft products: 0')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('Human Reviewed Gift', $product->fresh()->name);
    }

    public function test_ai_generated_copy_is_excluded_on_a_resumed_run(): void
    {
        $this->configureCuratedAmazonMerchant();
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'name' => 'Concise Gift Title',
                            'short_description' => 'A factual and concise description of this gift.',
                            'description' => "Useful for everyday moments\nEasy to enjoy right away\nThoughtful without being overly personal",
                        ]),
                    ],
                ]],
            ]),
        ]);

        $merchant = Merchant::query()->where('slug', 'amazon-in')->firstOrFail();
        $product = Product::factory()->draft()->create([
            'name' => 'Long Marketplace Gift Title',
            'editorial_ownership' => EditorialOwnership::Source,
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/B0RESUME01?tag=test-tag-20',
            'external_product_id' => 'B0RESUME01',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);

        $this->artisan('catalog:refresh-curated-editorial', [
            '--product' => (string) $product->id,
            '--execute' => true,
        ])->assertSuccessful();

        $this->artisan('catalog:refresh-curated-editorial', [
            '--product' => (string) $product->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Excluded: already_ai_generated_current_version')
            ->expectsOutputToContain('AI calls if executed: 0')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(EditorialOwnership::Ai, $product->fresh()->editorial_ownership);
    }
}
