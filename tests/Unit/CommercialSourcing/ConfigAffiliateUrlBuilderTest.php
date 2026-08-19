<?php

namespace Tests\Unit\CommercialSourcing;

use App\CommercialSourcing\Affiliate\ConfigAffiliateUrlBuilder;
use App\CommercialSourcing\SourcedMerchantOffer;
use App\Enums\CommercialExternalIdSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConfigAffiliateUrlBuilderTest extends TestCase
{
    public function test_query_param_injects_only_the_configured_parameter(): void
    {
        Http::preventStrayRequests();

        $result = $this->builder()->build(
            $this->offer('https://partner-a.example/dp/B0ABCDEFGH?ref=nav'),
            $this->config([
                'affiliate' => [
                    'strategy' => 'query_param',
                    'param' => 'aff',
                    'value' => 'test-tag',
                ],
            ]),
        );

        $this->assertTrue($result->ready);
        $this->assertSame('query_param', $result->strategy);
        $this->assertNotNull($result->url);
        $this->assertStringContainsString('aff=test-tag', $result->url);
        $this->assertStringContainsString('ref=nav', $result->url);
        Http::assertNothingSent();
    }

    public function test_query_param_without_required_config_is_manual(): void
    {
        $result = $this->builder()->build(
            $this->offer('https://partner-a.example/dp/B0ABCDEFGH'),
            $this->config([
                'affiliate' => [
                    'strategy' => 'query_param',
                ],
            ]),
        );

        $this->assertFalse($result->ready);
        $this->assertNull($result->url);
        $this->assertSame('affiliate_manual', $result->reasonCode);
    }

    public function test_template_uses_configured_tokens(): void
    {
        $result = $this->builder()->build(
            $this->offer('https://partner-a.example/dp/B0ABCDEFGH'),
            $this->config([
                'affiliate' => [
                    'strategy' => 'template',
                    'template' => 'https://partner-a.example/out/{external_product_id}',
                ],
            ]),
        );

        $this->assertTrue($result->ready);
        $this->assertSame('https://partner-a.example/out/B0ABCDEFGH', $result->url);
    }

    public function test_passthrough_keeps_the_source_url_when_the_host_is_allowed(): void
    {
        $result = $this->builder()->build(
            $this->offer('https://partner-a.example/dp/B0ABCDEFGH'),
            $this->config([
                'affiliate' => [
                    'strategy' => 'passthrough',
                ],
            ]),
        );

        $this->assertTrue($result->ready);
        $this->assertSame('https://partner-a.example/dp/B0ABCDEFGH', $result->url);
    }

    public function test_manual_strategy_never_builds_a_url(): void
    {
        $result = $this->builder()->build(
            $this->offer('https://partner-a.example/dp/B0ABCDEFGH'),
            $this->config([
                'affiliate_strategy' => 'manual',
                'affiliate' => ['strategy' => 'manual'],
            ]),
        );

        $this->assertFalse($result->ready);
        $this->assertNull($result->url);
        $this->assertSame('affiliate_manual', $result->reasonCode);
    }

    public function test_off_domain_results_are_rejected(): void
    {
        $result = $this->builder()->build(
            $this->offer('https://evil.example/dp/B0ABCDEFGH'),
            $this->config([
                'affiliate' => [
                    'strategy' => 'query_param',
                    'param' => 'aff',
                    'value' => 'test-tag',
                ],
            ]),
        );

        $this->assertFalse($result->ready);
        $this->assertNull($result->url);
    }

    public function test_template_off_domain_result_is_rejected(): void
    {
        $result = $this->builder()->build(
            $this->offer('https://partner-a.example/dp/B0ABCDEFGH'),
            $this->config([
                'affiliate' => [
                    'strategy' => 'template',
                    'template' => 'https://tracker.example/click/{external_product_id}',
                ],
            ]),
        );

        $this->assertFalse($result->ready);
        $this->assertNull($result->url);
        $this->assertSame('affiliate_manual', $result->reasonCode);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_replace_recursive([
            'domains' => ['partner-a.example'],
            'affiliate_strategy' => 'query_param',
            'affiliate' => ['strategy' => 'query_param'],
        ], $overrides);
    }

    private function offer(string $url): SourcedMerchantOffer
    {
        return new SourcedMerchantOffer(
            merchantId: 1,
            merchantSlug: 'partner-a',
            sourceUrl: $url,
            normalizedUrl: $url,
            title: 'BrandX French Press',
            snippet: '₹1,299',
            externalProductId: 'B0ABCDEFGH',
            externalIdSource: CommercialExternalIdSource::Extracted,
            priceAmount: '1299.00',
            priceCurrency: 'INR',
            imageUrls: [],
            retrievedAt: now(),
            sourceEvidence: [],
            rankScore: 1,
        );
    }

    private function builder(): ConfigAffiliateUrlBuilder
    {
        return app(ConfigAffiliateUrlBuilder::class);
    }
}
