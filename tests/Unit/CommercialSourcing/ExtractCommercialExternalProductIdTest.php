<?php

namespace Tests\Unit\CommercialSourcing;

use App\CommercialSourcing\ExtractCommercialExternalProductId;
use App\Enums\CommercialExternalIdSource;
use Tests\Support\ConfiguresCommercialSourcing;
use Tests\TestCase;

class ExtractCommercialExternalProductIdTest extends TestCase
{
    use ConfiguresCommercialSourcing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCommercialMerchants([
            'partner-a' => $this->commercialMerchantConfig('partner-a', [
                'external_id_strategy' => 'extractor',
                'url_fingerprint' => [
                    'enabled' => false,
                    'strip_query_params' => ['tag', 'ref'],
                ],
            ]),
            'partner-b' => $this->commercialMerchantConfig('partner-b', [
                'external_id_strategy' => 'url_fingerprint',
                'url_fingerprint' => [
                    'enabled' => true,
                    'strip_query_params' => ['tag', 'ref'],
                ],
            ]),
            'partner-c' => $this->commercialMerchantConfig('partner-c', [
                'external_id_strategy' => 'manual',
            ]),
            'partner-d' => $this->commercialMerchantConfig('partner-d', [
                'external_id_strategy' => 'extractor',
                'url_fingerprint' => [
                    'enabled' => true,
                    'strip_query_params' => ['tag'],
                ],
            ]),
        ]);
    }

    public function test_it_extracts_an_id_from_a_path_regex(): void
    {
        $identity = app(ExtractCommercialExternalProductId::class)->execute(
            'partner-a',
            'https://partner-a.example/dp/B0ABCDEFGH?tag=aff',
        );

        $this->assertSame('B0ABCDEFGH', $identity->externalProductId);
        $this->assertSame(CommercialExternalIdSource::Extracted, $identity->source);
        $this->assertFalse($identity->unstableIdentity);
    }

    public function test_it_extracts_an_id_from_a_query_parameter(): void
    {
        $identity = app(ExtractCommercialExternalProductId::class)->execute(
            'partner-a',
            'https://partner-a.example/item?pid=SKU-99',
        );

        $this->assertSame('SKU-99', $identity->externalProductId);
        $this->assertSame(CommercialExternalIdSource::Extracted, $identity->source);
    }

    public function test_extractor_failure_does_not_invent_an_id(): void
    {
        $identity = app(ExtractCommercialExternalProductId::class)->execute(
            'partner-a',
            'https://partner-a.example/product/french-press',
        );

        $this->assertNull($identity->externalProductId);
        $this->assertSame(CommercialExternalIdSource::None, $identity->source);
    }

    public function test_url_fingerprint_is_used_only_when_enabled(): void
    {
        $without = app(ExtractCommercialExternalProductId::class)->execute(
            'partner-a',
            'https://partner-a.example/product/french-press',
        );
        $with = app(ExtractCommercialExternalProductId::class)->execute(
            'partner-b',
            'https://partner-b.example/product/french-press',
        );

        $this->assertNull($without->externalProductId);
        $this->assertSame(CommercialExternalIdSource::UrlFingerprint, $with->source);
        $this->assertNotNull($with->externalProductId);
        $this->assertTrue(str_starts_with((string) $with->externalProductId, 'url:'));
    }

    public function test_manual_strategy_leaves_identity_null(): void
    {
        $identity = app(ExtractCommercialExternalProductId::class)->execute(
            'partner-c',
            'https://partner-c.example/dp/B0ABCDEFGH',
        );

        $this->assertNull($identity->externalProductId);
        $this->assertSame(CommercialExternalIdSource::None, $identity->source);
        $this->assertFalse($identity->unstableIdentity);
    }

    public function test_search_urls_are_unstable_and_are_not_fingerprinted(): void
    {
        $identity = app(ExtractCommercialExternalProductId::class)->execute(
            'partner-d',
            'https://partner-d.example/s?k=french+press',
        );

        $this->assertNull($identity->externalProductId);
        $this->assertTrue($identity->unstableIdentity);
        $this->assertSame(CommercialExternalIdSource::None, $identity->source);
    }

    public function test_tracking_params_do_not_change_a_fingerprint(): void
    {
        $extractor = app(ExtractCommercialExternalProductId::class);

        $plain = $extractor->execute('partner-b', 'https://partner-b.example/product/press');
        $tracked = $extractor->execute('partner-b', 'https://partner-b.example/product/press?tag=x&ref=y');

        $this->assertSame($plain->externalProductId, $tracked->externalProductId);
    }

    public function test_different_merchants_never_share_a_fingerprint(): void
    {
        config([
            'commercial_sourcing.merchants.partner-e' => $this->commercialMerchantConfig('partner-e', [
                'external_id_strategy' => 'url_fingerprint',
                'url_fingerprint' => [
                    'enabled' => true,
                    'strip_query_params' => [],
                ],
            ]),
        ]);

        $first = app(ExtractCommercialExternalProductId::class)->execute(
            'partner-b',
            'https://shared.example/product/press',
        );
        $second = app(ExtractCommercialExternalProductId::class)->execute(
            'partner-e',
            'https://shared.example/product/press',
        );

        $this->assertNotSame($first->externalProductId, $second->externalProductId);
    }

    public function test_extractor_can_fall_back_to_fingerprint_when_configured(): void
    {
        $identity = app(ExtractCommercialExternalProductId::class)->execute(
            'partner-d',
            'https://partner-d.example/product/french-press',
        );

        $this->assertSame(CommercialExternalIdSource::UrlFingerprint, $identity->source);
        $this->assertNotNull($identity->externalProductId);
    }
}
