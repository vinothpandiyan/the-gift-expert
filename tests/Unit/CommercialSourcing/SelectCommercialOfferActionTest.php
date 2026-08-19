<?php

namespace Tests\Unit\CommercialSourcing;

use App\Actions\CatalogCandidate\SelectCommercialOfferAction;
use App\CommercialSourcing\SourcedMerchantOffer;
use App\Enums\CommercialExternalIdSource;
use App\Models\CatalogCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConfiguresCommercialSourcing;
use Tests\TestCase;

class SelectCommercialOfferActionTest extends TestCase
{
    use ConfiguresCommercialSourcing;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCommercialMerchants([
            'partner-a' => $this->commercialMerchantConfig('partner-a', [
                'priority' => 40,
                'affiliate_enabled' => true,
                'affiliate_strategy' => 'query_param',
            ]),
            'partner-b' => $this->commercialMerchantConfig('partner-b', [
                'priority' => 90,
                'affiliate_enabled' => true,
                'affiliate_strategy' => 'query_param',
            ]),
            'partner-cheap' => $this->commercialMerchantConfig('partner-cheap', [
                'priority' => 10,
                'affiliate_enabled' => false,
                'affiliate_strategy' => 'manual',
            ]),
        ]);
    }

    public function test_it_prefers_affiliate_capable_priority_and_stable_external_ids(): void
    {
        $candidate = CatalogCandidate::factory()->make(['title' => 'French press']);

        $cheap = $this->offer('partner-cheap', 1, 'https://partner-cheap.example/dp/B0CHEAP0001', 'Cheap French press', '99.00', CommercialExternalIdSource::Extracted, 'B0CHEAP0001');
        $stable = $this->offer('partner-b', 2, 'https://partner-b.example/dp/B0STABLE001', 'French press coffee maker', '2499.00', CommercialExternalIdSource::Extracted, 'B0STABLE001');
        $weak = $this->offer('partner-a', 3, 'https://partner-a.example/product/press', 'Kitchen gadget', null, CommercialExternalIdSource::None, null);

        $selection = app(SelectCommercialOfferAction::class)->execute($candidate, [$cheap, $stable, $weak]);

        $this->assertNotNull($selection->selected);
        $this->assertSame('partner-b', $selection->selected->merchantSlug);
        $this->assertSame('B0STABLE001', $selection->selected->externalProductId);
        $this->assertSame(['partner-b', 'partner-cheap', 'partner-a'], array_map(
            fn (SourcedMerchantOffer $offer): string => $offer->merchantSlug,
            $selection->ordered,
        ));
        $this->assertArrayHasKey('merchant_priority', $selection->rankBreakdown);
        $this->assertGreaterThan($selection->ordered[1]->rankScore, $selection->ordered[0]->rankScore);
    }

    public function test_price_is_not_the_sole_ranking_factor(): void
    {
        $candidate = CatalogCandidate::factory()->make(['title' => 'French press']);

        $expensiveStable = $this->offer('partner-b', 1, 'https://partner-b.example/dp/B0HIGH00001', 'French press', '5000.00', CommercialExternalIdSource::Extracted, 'B0HIGH00001');
        $cheapUnstable = $this->offer('partner-cheap', 2, 'https://partner-cheap.example/s?k=press', 'French press ₹99', '99.00', CommercialExternalIdSource::None, null);

        $selection = app(SelectCommercialOfferAction::class)->execute($candidate, [$cheapUnstable, $expensiveStable]);

        $this->assertSame('partner-b', $selection->selected?->merchantSlug);
    }

    public function test_tie_break_is_deterministic(): void
    {
        $this->useCommercialMerchants([
            'alpha' => $this->commercialMerchantConfig('alpha', ['priority' => 50, 'affiliate_enabled' => true, 'affiliate_strategy' => 'query_param']),
            'beta' => $this->commercialMerchantConfig('beta', ['priority' => 50, 'affiliate_enabled' => true, 'affiliate_strategy' => 'query_param']),
        ]);

        $candidate = CatalogCandidate::factory()->make(['title' => 'French press']);
        $first = $this->offer('beta', 10, 'https://beta.example/dp/B0TIE000002', 'French press', '100.00', CommercialExternalIdSource::Extracted, 'B0TIE000002');
        $second = $this->offer('alpha', 11, 'https://alpha.example/dp/B0TIE000001', 'French press', '100.00', CommercialExternalIdSource::Extracted, 'B0TIE000001');

        $selection = app(SelectCommercialOfferAction::class)->execute($candidate, [$first, $second]);

        $this->assertSame('alpha', $selection->selected?->merchantSlug);
    }

    private function offer(
        string $slug,
        int $merchantId,
        string $url,
        string $title,
        ?string $price,
        CommercialExternalIdSource $source,
        ?string $externalId,
    ): SourcedMerchantOffer {
        return new SourcedMerchantOffer(
            merchantId: $merchantId,
            merchantSlug: $slug,
            sourceUrl: $url,
            normalizedUrl: $url,
            title: $title,
            snippet: $price !== null ? 'Price ₹'.$price : 'No price',
            externalProductId: $externalId,
            externalIdSource: $source,
            priceAmount: $price,
            priceCurrency: $price !== null ? 'INR' : null,
            imageUrls: [],
            retrievedAt: now(),
            sourceEvidence: [],
            rankScore: 0,
        );
    }
}
