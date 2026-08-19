<?php

namespace Tests\Unit\Actions\CatalogCandidate;

use App\Actions\CatalogCandidate\EnrichAndClassifyCommercialOfferAction;
use App\CommercialSourcing\CommercialEnrichmentException;
use App\CommercialSourcing\SourcedMerchantOffer;
use App\Enums\CatalogCandidateStatus;
use App\Enums\CommercialExternalIdSource;
use App\Models\CatalogCandidate;
use App\Models\Category;
use App\Models\Merchant;
use Database\Seeders\BudgetRangeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConfiguresCommercialSourcing;
use Tests\Support\FakesCommercialEnrichment;
use Tests\TestCase;

class EnrichAndClassifyCommercialOfferActionTest extends TestCase
{
    use ConfiguresCommercialSourcing;
    use FakesCommercialEnrichment;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureCommercialEnrichment();
        $this->seed(BudgetRangeSeeder::class);
        $this->useCommercialMerchants([
            'partner-a' => $this->commercialMerchantConfig('partner-a', [
                'domains' => ['partner-a.example'],
                'affiliate' => [
                    'strategy' => 'query_param',
                    'param' => 'aff',
                    'value' => 'test-tag',
                ],
            ]),
        ]);
        $this->createActiveMerchant('partner-a');
    }

    public function test_it_makes_one_llm_call_and_ignores_model_controlled_commercial_fields(): void
    {
        $home = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'summary' => 'Brew coffee at home.',
            'status' => CatalogCandidateStatus::Approved,
        ]);
        $offer = $this->offer();

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                    ],
                    'extra' => [
                        'price' => '1.00',
                        'affiliate_url' => 'https://evil.example/x',
                        'external_product_id' => 'FAKEID',
                        'image_urls' => ['https://evil.example/img.jpg'],
                    ],
                ]),
            ),
        ]);

        $payload = app(EnrichAndClassifyCommercialOfferAction::class)->execute($candidate, $offer);

        $this->assertSame('BrandX French Press', $payload->name);
        $this->assertSame('1299.00', $payload->priceAmount);
        $this->assertNotSame('1.00', $payload->priceAmount);
        $this->assertSame('B0ABCDEFGH', $payload->externalProductId);
        $this->assertNotSame('FAKEID', $payload->externalProductId);
        $this->assertTrue($payload->affiliateReady);
        $this->assertIsString($payload->affiliateUrl);
        $this->assertStringContainsString('aff=test-tag', $payload->affiliateUrl);
        $this->assertSame($home->id, $payload->primaryCategoryId);
        $this->assertSame([], $payload->imageUrls);
        $this->assertNotContains('https://evil.example/x', [$payload->affiliateUrl]);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && ($data['response_format']['json_schema']['name'] ?? null) === 'commercial_offer_enrichment'
                && ! array_key_exists('tools', $data)
                && ! array_key_exists('web_search_options', $data);
        });
    }

    public function test_affiliate_manual_does_not_block_enrichment(): void
    {
        $this->useCommercialMerchants([
            'partner-a' => $this->commercialMerchantConfig('partner-a', [
                'domains' => ['partner-a.example'],
                'affiliate_strategy' => 'manual',
                'affiliate' => ['strategy' => 'manual'],
            ]),
        ]);

        $home = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                    ],
                ]),
            ),
        ]);

        $payload = app(EnrichAndClassifyCommercialOfferAction::class)->execute($candidate, $this->offer());

        $this->assertFalse($payload->affiliateReady);
        $this->assertNull($payload->affiliateUrl);
        $this->assertContains('affiliate_manual', $payload->exceptionCodes);
        $this->assertSame('BrandX French Press', $payload->name);
    }

    public function test_malformed_json_is_a_hard_failure(): void
    {
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => '{']],
                ],
            ]),
        ]);

        $this->expectException(CommercialEnrichmentException::class);

        app(EnrichAndClassifyCommercialOfferAction::class)->execute($candidate, $this->offer());
    }

    public function test_physical_goods_stub_with_empty_gift_types_is_accepted(): void
    {
        $home = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                        'gift_type_ids' => [],
                        'profession_ids' => [],
                    ],
                ]),
            ),
        ]);

        $payload = app(EnrichAndClassifyCommercialOfferAction::class)->execute($candidate, $this->offer());

        $this->assertSame([], $payload->giftTypeIds);
        $this->assertSame([], $payload->professionIds);
    }

    public function test_ambiguous_price_sets_exception_and_skips_budget(): void
    {
        $home = Category::query()->create([
            'name' => 'Home & Living',
            'slug' => 'home-and-living',
            'is_active' => true,
        ]);
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'status' => CatalogCandidateStatus::Approved,
        ]);
        $offer = $this->offer(snippet: 'Was ₹1,999 now ₹1,299');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                $this->commercialEnrichmentCompletion([
                    'taxonomy' => [
                        'primary_category_id' => $home->id,
                        'category_ids' => [$home->id],
                    ],
                ]),
            ),
        ]);

        $payload = app(EnrichAndClassifyCommercialOfferAction::class)->execute($candidate, $offer);

        $this->assertNull($payload->priceAmount);
        $this->assertNull($payload->budgetRangeId);
        $this->assertContains('missing_or_ambiguous_price', $payload->exceptionCodes);
    }

    private function offer(string $snippet = 'BrandX French Press ₹1,299'): SourcedMerchantOffer
    {
        $merchantId = (int) Merchant::query()->where('slug', 'partner-a')->value('id');

        return new SourcedMerchantOffer(
            merchantId: $merchantId,
            merchantSlug: 'partner-a',
            sourceUrl: 'https://partner-a.example/dp/B0ABCDEFGH',
            normalizedUrl: 'https://partner-a.example/dp/B0ABCDEFGH',
            title: 'BrandX French Press',
            snippet: $snippet,
            externalProductId: 'B0ABCDEFGH',
            externalIdSource: CommercialExternalIdSource::Extracted,
            priceAmount: '1299.00',
            priceCurrency: 'INR',
            imageUrls: ['javascript:alert(1)'],
            retrievedAt: now(),
            sourceEvidence: [],
            rankScore: 1,
        );
    }
}
